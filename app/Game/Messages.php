<?php

namespace App\Game;

use App\Events\MessageSent;
use App\Game\Translation\Translator;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\MessageReport;
use App\Models\Player;
use App\Models\PlayerBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Private messages. Any animal can write to any other, in one conversation
 * per pair. A reader can block the writer, or report a message to our team,
 * who can hide it and stop the writer writing for some days. A message is
 * translated into its reader's language on request, like chat.
 */
final class Messages
{
    public const MAX_LENGTH = 1000;

    // Friends keep writing to each other; a spammer writes to strangers.
    public const NEW_CONVERSATIONS_PER_DAY = 20;

    public const REPORT_REASONS = [
        'unkind' => 'Bullying, threats or hate',
        'spam' => 'Spam or a scam',
        'real_world' => 'Real politics, real people or contact details',
        'other' => 'Something else',
    ];

    public const MUTE_DAYS = [1, 3, 7, 30];

    public function __construct(private readonly Translator $translator) {}

    public static function between(Player $a, Player $b): ?Conversation
    {
        return Conversation::query()
            ->where('player_one_id', min($a->id, $b->id))
            ->where('player_two_id', max($a->id, $b->id))
            ->first();
    }

    /** Whether the player has blocked the other. */
    public static function blocks(Player $player, Player $other): bool
    {
        return PlayerBlock::query()->where('player_id', $player->id)->where('blocked_id', $other->id)->exists();
    }

    /** Why the player can't write to the other right now, or null when they can. */
    public static function cantWrite(Player $player, Player $other): ?string
    {
        return match (true) {
            $player->is($other) => "You can't write to yourself.",
            Players::silenced($player) !== null => Players::silenced($player),
            self::blocks($player, $other) => "You blocked {$other->name}. Unblock them to write.",
            self::blocks($other, $player) => "{$other->name} isn't taking messages from you.",
            default => null,
        };
    }

    /**
     * @throws GameException
     */
    public function send(Player $from, Player $to, string $body): DirectMessage
    {
        $body = trim($body);

        if (($reason = self::cantWrite($from, $to)) !== null) {
            throw new GameException($reason);
        }

        if ($body === '') {
            throw new GameException('Write something first.');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new GameException('Keep messages to '.self::MAX_LENGTH.' characters.');
        }

        return DB::transaction(function () use ($from, $to, $body) {
            $conversation = self::between($from, $to) ?? $this->open($from, $to);

            $message = DirectMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $from->id,
                'recipient_id' => $to->id,
                'body' => $body,
            ]);

            // Two messages sent at once must not leave the older one as the last.
            Conversation::query()
                ->whereKey($conversation->id)
                ->where(fn ($query) => $query->whereNull('last_message_id')->orWhere('last_message_id', '<', $message->id))
                ->update(['last_message_id' => $message->id, 'updated_at' => CarbonImmutable::now()]);

            Live::send(MessageSent::of($message, $from->name));

            return $message;
        });
    }

    /**
     * @throws GameException
     */
    private function open(Player $from, Player $to): Conversation
    {
        $started = Conversation::query()
            ->where('started_by_id', $from->id)
            ->where('created_at', '>=', Clock::startOfToday())
            ->count();

        if ($started >= self::NEW_CONVERSATIONS_PER_DAY) {
            throw new GameException('You can start '.self::NEW_CONVERSATIONS_PER_DAY.' new conversations a day. Write to animals you already know, or try again tomorrow.');
        }

        return Conversation::query()->createOrFirst(
            ['player_one_id' => min($from->id, $to->id), 'player_two_id' => max($from->id, $to->id)],
            ['started_by_id' => $from->id],
        );
    }

    /**
     * The player's conversations, latest first, with what was said last and
     * how many messages wait unread in each.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function inbox(Player $player, int $limit = 50): Collection
    {
        $conversations = Conversation::query()
            ->whereAny(['player_one_id', 'player_two_id'], $player->id)
            ->whereNotNull('last_message_id')
            ->with([
                'lastMessage',
                'playerOne:id,name,species,faction_id,orchard_until', 'playerOne.faction:id,name,colour',
                'playerTwo:id,name,species,faction_id,orchard_until', 'playerTwo.faction:id,name,colour',
            ])
            ->orderByDesc('last_message_id')
            ->limit($limit)
            ->get();

        $unread = DirectMessage::query()
            ->where('recipient_id', $player->id)
            ->whereNull('read_at')
            ->whereIn('conversation_id', $conversations->modelKeys())
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, count(*) as unread')
            ->pluck('unread', 'conversation_id');

        $blocked = PlayerBlock::query()->where('player_id', $player->id)->pluck('blocked_id')->flip();

        return $conversations
            ->filter(fn (Conversation $conversation) => $conversation->lastMessage !== null)
            ->map(function (Conversation $conversation) use ($player, $unread, $blocked) {
                $other = $conversation->other($player);
                $last = $conversation->lastMessage;

                return [
                    'animal' => self::card($other),
                    'last' => [
                        'body' => $last->hidden_at === null ? Str::limit($last->body, 120) : null,
                        'mine' => $last->sender_id === $player->id,
                        'at' => $last->created_at->toIso8601String(),
                    ],
                    'unread' => (int) ($unread[$conversation->id] ?? 0),
                    'blocked' => $blocked->has($other->id),
                ];
            })
            ->values();
    }

    /**
     * The latest messages in a conversation, oldest first, as the player
     * sees them. Only the reader learns that a message was reported.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function thread(Player $player, Conversation $conversation, int $limit = 100): Collection
    {
        return $conversation->messages()
            ->with('report:id,direct_message_id')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(function (DirectMessage $message) use ($player) {
                $mine = $message->sender_id === $player->id;
                $hidden = $message->hidden_at !== null;

                return [
                    'id' => $message->id,
                    'body' => $hidden ? null : $message->body,
                    'translation' => ! $hidden && $message->translation_language === $player->language ? $message->translation : null,
                    'at' => $message->created_at->toIso8601String(),
                    'mine' => $mine,
                    'read' => $mine && $message->read_at !== null,
                    'reported' => ! $mine && $message->report !== null,
                ];
            });
    }

    public function markRead(Player $player, Conversation $conversation): void
    {
        DirectMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('recipient_id', $player->id)
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now()]);
    }

    public static function unread(Player $player): int
    {
        return DirectMessage::query()->where('recipient_id', $player->id)->whereNull('read_at')->count();
    }

    /**
     * Animals whose name contains the search, names starting with it first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function find(Player $player, string $search, int $limit = 10): Collection
    {
        $escaped = addcslashes($search, '%_\\');

        return Player::query()
            ->with('faction:id,name,colour')
            ->whereKeyNot($player->id)
            ->where('name', 'like', "%{$escaped}%")
            ->orderByRaw('name like ? desc', ["{$escaped}%"])
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'species', 'faction_id', 'orchard_until'])
            ->map(fn (Player $other) => self::card($other));
    }

    /** @return array{id: int, name: string, species: string, faction: string|null, colour: string|null, orchard: bool} */
    public static function card(Player $player): array
    {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'species' => $player->species->value,
            'faction' => $player->faction?->name,
            'colour' => $player->faction?->colour,
            'orchard' => $player->inOrchard(),
        ];
    }

    public function canTranslate(): bool
    {
        return $this->translator->available();
    }

    /**
     * @throws GameException
     */
    public function translate(Player $player, DirectMessage $message): string
    {
        if ($message->recipient_id !== $player->id || $message->hidden_at !== null) {
            throw new GameException("You can't read that message.");
        }

        if (! $this->translator->available()) {
            throw new GameException("Translation isn't switched on yet.");
        }

        if ($message->translation !== null && $message->translation_language === $player->language) {
            return $message->translation;
        }

        $translated = $this->translator->translate($message->body, $player->language)
            ?? throw new GameException("That message couldn't be translated. Try again in a moment.");

        $message->forceFill(['translation' => $translated, 'translation_language' => $player->language])->save();

        return $translated;
    }

    /**
     * @throws GameException
     */
    public function block(Player $player, Player $other): void
    {
        if ($player->is($other)) {
            throw new GameException("You can't block yourself.");
        }

        PlayerBlock::query()->firstOrCreate(['player_id' => $player->id, 'blocked_id' => $other->id]);
    }

    public function unblock(Player $player, Player $other): void
    {
        PlayerBlock::query()->where('player_id', $player->id)->where('blocked_id', $other->id)->delete();
    }

    /**
     * @throws GameException
     */
    public function report(Player $player, DirectMessage $message, string $reason, ?string $note = null, bool $block = false): MessageReport
    {
        if ($message->recipient_id !== $player->id) {
            throw new GameException('You can only report messages sent to you.');
        }

        if (! array_key_exists($reason, self::REPORT_REASONS)) {
            throw new GameException('Pick a reason from the list.');
        }

        $report = MessageReport::query()->firstOrCreate(
            ['direct_message_id' => $message->id],
            ['reporter_id' => $player->id, 'reason' => $reason, 'note' => filled($note) ? trim($note) : null],
        );

        if ($block) {
            $this->block($player, $message->sender);
        }

        return $report;
    }

    /**
     * Our team's answer to a report: the message stands, or it is hidden and
     * the writer can't write in messages or chat for some days.
     *
     * @throws GameException
     */
    public function review(MessageReport $report, User $staff, ?int $muteDays): void
    {
        if ($report->reviewed_at !== null) {
            throw new GameException('Someone on the team already dealt with that report.');
        }

        if ($muteDays !== null && ! in_array($muteDays, self::MUTE_DAYS, true)) {
            throw new GameException('Pick how many days to mute for.');
        }

        DB::transaction(function () use ($report, $staff, $muteDays) {
            $now = CarbonImmutable::now();

            $report->forceFill([
                'outcome' => $muteDays === null ? 'dismissed' : 'muted',
                'reviewed_by' => $staff->id,
                'reviewed_at' => $now,
            ])->save();

            if ($muteDays === null) {
                return;
            }

            $message = $report->message;
            $message->forceFill(['hidden_at' => $now])->save();

            $writer = $message->sender;
            $until = $now->addDays($muteDays);

            if (! $writer->isMuted() || $writer->muted_until->lt($until)) {
                $writer->forceFill(['muted_until' => $until])->save();
            }
        });
    }
}
