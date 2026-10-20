<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BugReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The owner's bug reports: read them, watch the video, log in as the player, mark them fixed. */
class BugReportController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), BugReport::STATUSES, true) ? $request->query('status') : 'open';

        return Inertia::render('admin/bugs', [
            'status' => $status,
            'counts' => BugReport::query()->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status'),
            'reports' => BugReport::query()
                ->with(['user:id,name,email,is_admin', 'player:id,name,valley_id,species'])
                ->where('status', $status)
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (BugReport $report) => [
                    'id' => $report->id,
                    'title' => $report->title,
                    'description' => $report->description,
                    'page' => $report->page,
                    'device' => $report->device,
                    'status' => $report->status,
                    'note' => $report->note,
                    'at' => $report->created_at->toIso8601String(),
                    'user' => ['id' => $report->user->id, 'name' => $report->user->name, 'email' => $report->user->email, 'admin' => (bool) $report->user->is_admin],
                    'player' => $report->player ? ['id' => $report->player->id, 'name' => $report->player->name, 'species' => $report->player->species->value] : null,
                    'attachment' => $report->attachment_path ? ['url' => route('admin.bugs.attachment', $report), 'video' => str_starts_with((string) $report->attachment_mime, 'video/')] : null,
                ]),
        ]);
    }

    public function update(Request $request, BugReport $report): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(BugReport::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $report->forceFill(['status' => $data['status'], 'note' => $data['note'] ?? $report->note])->save();

        return $this->told("Bug report #{$report->id} is {$data['status']}.");
    }

    /** The photo or video, only for the owners: it lives on the private disk. */
    public function attachment(BugReport $report): StreamedResponse
    {
        abort_if($report->attachment_path === null || ! Storage::disk('local')->exists($report->attachment_path), 404);

        return Storage::disk('local')->response($report->attachment_path, null, ['Content-Type' => $report->attachment_mime ?? 'application/octet-stream']);
    }
}
