<?php

namespace App\Http\Controllers\Admin;

use App\Game\Banners;
use App\Http\Controllers\Controller;
use App\Models\BugReport;
use App\Models\Faction;
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
        $kind = $request->query('kind') === 'cheat' ? 'cheat' : 'bug';

        return Inertia::render('admin/bugs', [
            'status' => $status,
            'kind' => $kind,
            'counts' => BugReport::query()->where('kind', $kind)->groupBy('status')->selectRaw('status, COUNT(*) AS total')->pluck('total', 'status'),
            'openCheats' => BugReport::query()->where('kind', 'cheat')->where('status', 'open')->count(),
            'rules' => config('fairplay.rules'),
            'reports' => BugReport::query()
                ->with(['user:id,name,email,is_admin', 'player:id,name,valley_id,species', 'reported:id,name,species,user_id', 'reported.user:id,name,email,is_admin'])
                ->where('kind', $kind)
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
                    'rule' => $report->rule,
                    'reported' => $report->reported ? ['id' => $report->reported->id, 'name' => $report->reported->name, 'species' => $report->reported->species->value, 'userId' => $report->reported->user_id, 'userEmail' => $report->reported->user?->email, 'admin' => (bool) $report->reported->user?->is_admin] : null,
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

    /** Take down a faction's uploaded banner picture: offensive or reported. */
    public function takeDownBanner(Faction $faction): RedirectResponse
    {
        Banners::takeDown($faction);

        return $this->told("{$faction->name}'s banner picture is down.");
    }

    /** The photo or video, only for the owners: it lives on the private disk. */
    public function attachment(BugReport $report): StreamedResponse
    {
        abort_if($report->attachment_path === null || ! Storage::disk('local')->exists($report->attachment_path), 404);

        return Storage::disk('local')->response($report->attachment_path, null, ['Content-Type' => $report->attachment_mime ?? 'application/octet-stream']);
    }
}
