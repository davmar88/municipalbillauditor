<?php

namespace App\Http\Controllers\Game;

use App\Game\Notices;
use App\Http\Controllers\Controller;
use App\Models\BugReport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reporting a bug, with a photo or a short video of it. */
class BugReportController extends Controller
{
    /** The biggest photo or video a report takes, in kilobytes. */
    public const MAX_KB = 51200;

    public function create(Request $request): Response
    {
        return Inertia::render('game/report-bug', [
            'from' => (string) $request->query('from', ''),
            'maxMb' => intdiv(self::MAX_KB, 1024),
            'mine' => BugReport::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (BugReport $report) => [
                    'id' => $report->id,
                    'title' => $report->title,
                    'status' => $report->status,
                    'note' => $report->note,
                    'at' => $report->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'page' => ['nullable', 'string', 'max:255'],
            'attachment' => ['nullable', 'file', 'max:'.self::MAX_KB, 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm'],
        ], [
            'attachment.max' => 'A photo or video can be at most '.intdiv(self::MAX_KB, 1024).' MB.',
            'attachment.mimetypes' => 'Add a photo (JPG, PNG, WebP, GIF) or a video (MP4, MOV, WebM).',
        ]);

        $file = $request->file('attachment');
        $report = BugReport::create([
            'user_id' => $request->user()->id,
            'player_id' => $request->user()->player?->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'page' => $data['page'] ?? null,
            'attachment_path' => $file?->store('bug-reports', 'local'),
            'attachment_mime' => $file?->getMimeType(),
            'device' => substr((string) $request->userAgent(), 0, 255),
        ]);

        // The owners hear of it.
        $admins = User::query()->where('is_admin', true)->with('player:id,user_id')->get()->pluck('player.id')->filter();
        Notices::send($admins, 'bug', "🐞 Bug report #{$report->id}: {$report->title}", route('admin.bugs', [], false));

        return $this->told("Thanks. Bug report #{$report->id} is in, and we'll look at it.");
    }
}
