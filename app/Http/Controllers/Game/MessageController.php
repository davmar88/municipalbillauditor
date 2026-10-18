<?php

namespace App\Http\Controllers\Game;

use App\Game\Messages;
use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Private messages: your inbox, and a conversation with any other animal. */
class MessageController extends Controller
{
    public function index(Request $request, Messages $messages): Response
    {
        $player = $request->user()->player;
        $search = trim((string) $request->query('q', ''));

        return Inertia::render('game/messages', [
            'conversations' => $messages->inbox($player),
            'search' => $search,
            'found' => fn () => mb_strlen($search) >= 2 ? $messages->find($player, $search) : [],
        ]);
    }

    public function show(Request $request, Player $player, Messages $messages): Response|RedirectResponse
    {
        $me = $request->user()->player;

        if ($me->is($player)) {
            return to_route('play.messages');
        }

        $conversation = Messages::between($me, $player);

        if ($conversation !== null) {
            $messages->markRead($me, $conversation);
        }

        return Inertia::render('game/conversation', [
            'animal' => Messages::card($player->load('faction:id,name,colour')),
            'messages' => $conversation !== null ? $messages->thread($me, $conversation) : [],
            'blocked' => Messages::blocks($me, $player),
            'cantWrite' => Messages::cantWrite($me, $player),
            'canTranslate' => $messages->canTranslate(),
            'language' => $me->language,
            'maxLength' => Messages::MAX_LENGTH,
            'reasons' => collect(Messages::REPORT_REASONS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request, Player $player, Messages $messages): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.Messages::MAX_LENGTH],
        ]);

        $messages->send($request->user()->player, $player, $data['body']);

        return back();
    }

    /** The conversation for the chat window you open from any animal's name. Reading it marks it read. */
    public function talk(Request $request, Player $player, Messages $messages): JsonResponse
    {
        return response()->json($this->window($request->user()->player, $player, $messages));
    }

    /** Say something from the chat window, and get the conversation back. */
    public function say(Request $request, Player $player, Messages $messages): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.Messages::MAX_LENGTH],
        ]);

        $messages->send($request->user()->player, $player, $data['body']);

        return response()->json($this->window($request->user()->player, $player, $messages));
    }

    /** @return array<string, mixed> */
    private function window(Player $me, Player $other, Messages $messages): array
    {
        abort_if($me->is($other) || $me->valley_id !== $other->valley_id, 404);

        $conversation = Messages::between($me, $other);

        if ($conversation !== null) {
            $messages->markRead($me, $conversation);
        }

        return [
            'animal' => Messages::card($other->load('faction:id,name,colour')),
            'messages' => $conversation !== null ? $messages->thread($me, $conversation, 50) : [],
            'cantWrite' => Messages::cantWrite($me, $other),
            'maxLength' => Messages::MAX_LENGTH,
        ];
    }

    public function block(Request $request, Player $player, Messages $messages): RedirectResponse
    {
        $messages->block($request->user()->player, $player);

        return $this->told("You blocked {$player->name}. Neither of you can write to the other now.");
    }

    public function unblock(Request $request, Player $player, Messages $messages): RedirectResponse
    {
        $messages->unblock($request->user()->player, $player);

        return $this->told("You unblocked {$player->name}.");
    }

    public function translate(Request $request, DirectMessage $message, Messages $messages): JsonResponse
    {
        return response()->json(['translation' => $messages->translate($request->user()->player, $message)]);
    }

    public function report(Request $request, DirectMessage $message, Messages $messages): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(Messages::REPORT_REASONS))],
            'note' => ['nullable', 'string', 'max:300'],
            'block' => ['boolean'],
        ]);

        $block = (bool) ($data['block'] ?? false);
        $messages->report($request->user()->player, $message, $data['reason'], $data['note'] ?? null, $block);

        return $this->told($block
            ? "Thanks. Our team will read it, and {$message->sender->name} can't write to you any more."
            : 'Thanks. Our team will read it.');
    }
}
