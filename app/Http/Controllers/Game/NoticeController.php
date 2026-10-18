<?php

namespace App\Http\Controllers\Game;

use App\Game\Messages;
use App\Game\Notices;
use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\Notice;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The bell, and the pulse every game screen checks for new messages and news. */
class NoticeController extends Controller
{
    /**
     * What's new since the screen last asked: unread counts, and the messages
     * and news that arrived after the ids it has seen, for pop-ups. The first
     * ask, with no ids, only learns where things stand.
     */
    public function pulse(Request $request): JsonResponse
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $messagesAfter = $request->has('messages') ? max(0, (int) $request->query('messages')) : null;
        $noticesAfter = $request->has('notices') ? max(0, (int) $request->query('notices')) : null;

        $messages = $messagesAfter === null ? collect() : DirectMessage::query()
            ->with(['sender:id,name,species,faction_id,orchard_until', 'sender.faction:id,name,colour'])
            ->where('recipient_id', $player->id)
            ->where('id', '>', $messagesAfter)
            ->whereNull('read_at')
            ->whereNull('hidden_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (DirectMessage $message) => [
                'id' => $message->id,
                'from' => Messages::card($message->sender),
                'body' => Str::limit($message->body, 140),
            ]);

        return response()->json([
            'unreadMessages' => Messages::unread($player),
            'unreadNotices' => Notices::unread($player),
            'latestMessage' => (int) DirectMessage::query()->where('recipient_id', $player->id)->max('id'),
            'latestNotice' => (int) Notice::query()->where('player_id', $player->id)->max('id'),
            'messages' => $messages->values(),
            'notices' => $noticesAfter === null ? [] : Notices::latest($player, 3, $noticesAfter)->values(),
        ]);
    }

    /** The bell's list: the latest news, newest first. */
    public function index(Request $request): JsonResponse
    {
        $player = $request->user()->player;

        return response()->json([
            'notices' => Notices::latest($player),
            'unread' => Notices::unread($player),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        Notices::markRead($request->user()->player);

        return response()->json(['unread' => 0]);
    }
}
