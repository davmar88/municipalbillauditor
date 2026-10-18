<?php

namespace App\Http\Controllers\Game;

use App\Game\Trees;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Apple trees: planting them with Pineapples, and harvesting their Apples. */
class TreeController extends Controller
{
    public function plant(Request $request, Trees $trees): RedirectResponse
    {
        $tree = $trees->plant($request->user()->player);

        return $this->told('You planted an apple tree. It fruits on '.$tree->fruits_at->setTimezone(config('game.timezone'))->format('l \a\t H:i').'.');
    }

    public function harvest(Request $request, Trees $trees): RedirectResponse
    {
        $apples = $trees->harvest($request->user()->player);

        return $this->told('You harvested '.number_format($apples).' '.($apples === 1 ? 'Apple' : 'Apples').'.');
    }
}
