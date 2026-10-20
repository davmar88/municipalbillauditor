<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GameUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's side of the updates page: add what's new or fixed, remove a line. */
class GameUpdateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/updates', [
            'updates' => GameUpdate::query()->latest('published_on')->latest('id')->limit(100)->get()->map(fn (GameUpdate $update) => [
                'id' => $update->id,
                'on' => $update->published_on->toDateString(),
                'kind' => $update->kind,
                'title' => $update->title,
                'body' => $update->body,
            ]),
            'publicUrl' => route('updates'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(GameUpdate::KINDS)],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        GameUpdate::create([...$data, 'published_on' => now()->toDateString()]);

        return $this->told('Posted on the updates page.');
    }

    public function destroy(GameUpdate $update): RedirectResponse
    {
        $update->delete();

        return $this->told('Taken off the updates page.');
    }
}
