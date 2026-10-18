<?php

namespace App\Game;

use App\Game\Translation\Translator;
use App\Models\ChatMessage;
use App\Models\ChatTranslation;
use App\Models\Faction;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Three kinds of channel: the valley square everyone reads, each faction's
 * own channel, and a channel between every pair of factions for deals,
 * threats and pacts. Any message can be translated into the reader's
 * language, once per language.
 */
final class Chat
{
    public const MAX_LENGTH = 500;

    public function __construct(private readonly Translator $translator) {}

    public static function valley(): string
    {
        return 'valley';
    }

    public static function faction(int $id): string
    {
        return "faction:{$id}";
    }

    public static function between(int $a, int $b): string
    {
        return 'between:'.min($a, $b).':'.max($a, $b);
    }

    /**
     * The channels a player can read: the square, their faction, and one
     * with each other faction.
     *
     * @return array<int, array{key: string, label: string, colour: string|null}>
     */
    public function channels(Player $player): array
    {
        $channels = [['key' => self::valley(), 'label' => 'Valley square', 'colour' => null]];

        if ($player->faction !== null) {
            $channels[] = ['key' => self::faction($player->faction_id), 'label' => $player->faction->name, 'colour' => $player->faction->colour];

            Faction::query()
                ->where('valley_id', $player->valley_id)
                ->whereKeyNot($player->faction_id)
                ->orderBy('name')
                ->get()
                ->each(function (Faction $other) use (&$channels, $player) {
                    $channels[] = ['key' => self::between($player->faction_id, $other->id), 'label' => "With {$other->name}", 'colour' => $other->colour];
                });
        }

        return $channels;
    }

    public function canRead(Player $player, string $channel): bool
    {
        if ($channel === self::valley()) {
            return true;
        }

        if ($player->faction_id === null) {
            return false;
        }

        if ($channel === self::faction($player->faction_id)) {
            return true;
        }

        if (preg_match('/^between:(\d+):(\d+)$/', $channel, $match) !== 1) {
            return false;
        }

        $ids = [(int) $match[1], (int) $match[2]];

        return in_array($player->faction_id, $ids, true)
            && Faction::query()->where('valley_id', $player->valley_id)->whereKey($ids)->count() === 2;
    }

    /**
     * @throws GameException
     */
    public function post(Player $player, string $channel, string $body): ChatMessage
    {
        $body = trim($body);

        if (! $this->canRead($player, $channel)) {
            throw new GameException("You can't write in that channel.");
        }

        if (($silenced = Players::silenced($player)) !== null) {
            throw new GameException($silenced);
        }

        if ($body === '') {
            throw new GameException('Write something first.');
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new GameException('Keep messages to '.self::MAX_LENGTH.' characters.');
        }

        $message = ChatMessage::create([
            'valley_id' => $player->valley_id,
            'channel' => $channel,
            'player_id' => $player->id,
            'body' => $body,
        ]);

        Missions::record($player, 'chat');

        return $message;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function messages(Player $player, string $channel, int $limit = 60): Collection
    {
        return ChatMessage::query()
            ->with(['player:id,name,species,faction_id', 'player.faction:id,name,colour'])
            ->with(['translations' => fn ($query) => $query->where('language', $player->language)])
            ->where('valley_id', $player->valley_id)
            ->where('channel', $channel)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ChatMessage $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'translation' => $message->translations->first()?->body,
                'at' => $message->created_at->toIso8601String(),
                'mine' => $message->player_id === $player->id,
                'from' => [
                    'id' => $message->player->id,
                    'name' => $message->player->name,
                    'species' => $message->player->species->value,
                    'faction' => $message->player->faction?->name,
                    'colour' => $message->player->faction?->colour,
                ],
            ]);
    }

    public function canTranslate(): bool
    {
        return $this->translator->available();
    }

    /**
     * @throws GameException
     */
    public function translate(Player $player, ChatMessage $message): string
    {
        if (! $this->canRead($player, $message->channel) || $message->valley_id !== $player->valley_id) {
            throw new GameException("You can't read that message.");
        }

        if (! $this->translator->available()) {
            throw new GameException("Translation isn't switched on yet.");
        }

        $existing = ChatTranslation::query()->where('chat_message_id', $message->id)->where('language', $player->language)->first();

        if ($existing !== null) {
            return $existing->body;
        }

        $translated = $this->translator->translate($message->body, $player->language)
            ?? throw new GameException("That message couldn't be translated. Try again in a moment.");

        ChatTranslation::query()->firstOrCreate(
            ['chat_message_id' => $message->id, 'language' => $player->language],
            ['body' => $translated],
        );

        return $translated;
    }
}
