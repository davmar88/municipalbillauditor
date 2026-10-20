<?php

namespace App\Http\Controllers\Game;

use App\Game\Banners;
use App\Http\Controllers\Controller;
use App\Models\Faction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A faction's banner: designed from a pattern, or uploaded. */
class BannerController extends Controller
{
    public function design(Request $request, Banners $banners): RedirectResponse
    {
        $data = $request->validate([
            'pattern' => ['required', Rule::in(Banners::PATTERNS)],
            'colours' => ['required', 'array', 'size:2'],
            'colours.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'emblem' => ['nullable', 'string', 'max:16'],
        ]);
        $banners->design($request->user()->player, $data['pattern'], $data['colours'], $data['emblem'] ?? '');

        return $this->told('Your faction flies its new banner.');
    }

    public function upload(Request $request, Banners $banners): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'max:'.Banners::MAX_KB, 'mimes:png,webp,jpg,jpeg', 'dimensions:min_width=64,min_height=64,max_width=2048,max_height=2048'],
        ], [
            'image.max' => 'A banner picture can be at most 1 MB.',
            'image.mimes' => 'Upload a PNG, WebP or JPG picture.',
            'image.dimensions' => 'Make it between 64 and 2048 pixels on each side.',
        ]);
        $banners->upload($request->user()->player, $request->file('image'));

        return $this->told('Your faction flies its new banner. Keep it clean: our team takes down anything offensive.');
    }

    public function takeDown(Request $request): RedirectResponse
    {
        Banners::takeDown(Banners::mine($request->user()->player));

        return $this->told('The picture is down; your faction flies its pattern banner again.');
    }

    /** The uploaded picture, for anyone to see. */
    public function show(Faction $faction): StreamedResponse
    {
        abort_if($faction->banner_path === null || ! Storage::disk('local')->exists($faction->banner_path), 404);

        return Storage::disk('local')->response($faction->banner_path, null, ['Cache-Control' => 'public, max-age=86400']);
    }
}
