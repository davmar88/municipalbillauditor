<?php

namespace App\Http\Controllers\Game;

use App\Game\Chat;
use App\Game\Players;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Chat: the valley square, your faction, and a channel with each other faction. */
class ChatController extends Controller
{
    public function index(Request $request, Chat $chat): Response
    {
        $player = $request->user()->player;
        $channels = $chat->channels($player);
        $channel = (string) $request->query('channel', $player->faction_id ? Chat::faction($player->faction_id) : Chat::valley());

        if (! $chat->canRead($player, $channel)) {
            $channel = Chat::valley();
        }

        return Inertia::render('game/chat', [
            'channels' => $channels,
            'channel' => $channel,
            'messages' => $chat->messages($player, $channel),
            'canTranslate' => $chat->canTranslate(),
            'language' => $player->language,
            'languages' => Players::LANGUAGES,
            'maxLength' => Chat::MAX_LENGTH,
            'silenced' => Players::silenced($player),
        ]);
    }

    public function store(Request $request, Chat $chat): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:40'],
            'body' => ['required', 'string', 'max:'.Chat::MAX_LENGTH],
        ]);

        $chat->post($request->user()->player, $data['channel'], $data['body']);

        return back();
    }

    public function translate(Request $request, ChatMessage $message, Chat $chat): JsonResponse
    {
        return response()->json(['translation' => $chat->translate($request->user()->player, $message)]);
    }
}
