<?php

namespace App\Http\Controllers\Staff;

use App\Game\Messages;
use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\MessageReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Private messages their readers reported. Our team sees the message and
 * the few before it, nothing else from the conversation.
 */
class ReportController extends Controller
{
    public function index(): Response
    {
        $reports = MessageReport::query()
            ->with(['message.sender:id,name,muted_until', 'reporter:id,name'])
            ->whereNull('reviewed_at')
            ->oldest('id')
            ->limit(50)
            ->get();

        return Inertia::render('staff/reports', [
            'reports' => $reports->map(function (MessageReport $report) {
                $message = $report->message;
                $writer = $message->sender;

                $before = DirectMessage::query()
                    ->where('conversation_id', $message->conversation_id)
                    ->where('id', '<', $message->id)
                    ->latest('id')
                    ->limit(4)
                    ->get()
                    ->reverse()
                    ->values();

                return [
                    'id' => $report->id,
                    'reason' => Messages::REPORT_REASONS[$report->reason] ?? $report->reason,
                    'note' => $report->note,
                    'at' => $report->created_at->toIso8601String(),
                    'reporter' => $report->reporter->name,
                    'writer' => [
                        'name' => $writer->name,
                        'mutedUntil' => $writer->isMuted() ? $writer->muted_until->toIso8601String() : null,
                        'mutedBefore' => MessageReport::query()
                            ->where('outcome', 'muted')
                            ->whereHas('message', fn ($query) => $query->where('sender_id', $writer->id))
                            ->count(),
                    ],
                    'message' => [
                        'body' => $message->body,
                        'translation' => $message->translation,
                        'at' => $message->created_at->toIso8601String(),
                    ],
                    'before' => $before->map(fn (DirectMessage $earlier) => [
                        'id' => $earlier->id,
                        'from' => $earlier->sender_id === $writer->id ? $writer->name : $report->reporter->name,
                        'body' => $earlier->body,
                        'at' => $earlier->created_at->toIso8601String(),
                    ]),
                ];
            }),
            'muteDays' => Messages::MUTE_DAYS,
        ]);
    }

    public function dismiss(Request $request, MessageReport $report, Messages $messages): RedirectResponse
    {
        $messages->review($report, $request->user(), null);

        return $this->told('The message stands.');
    }

    public function mute(Request $request, MessageReport $report, Messages $messages): RedirectResponse
    {
        $days = (int) $request->validate(['days' => ['required', 'integer', Rule::in(Messages::MUTE_DAYS)]])['days'];
        $messages->review($report, $request->user(), $days);

        return $this->told("Hidden the message and muted {$report->message->sender->name} for {$days} ".($days === 1 ? 'day' : 'days').'.');
    }
}
