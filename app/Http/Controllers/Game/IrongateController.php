<?php

namespace App\Http\Controllers\Game;

use App\Game\Irongate;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Warrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Dealing with Irongate's people: the fence and the informer. */
class IrongateController extends Controller
{
    public function fence(Request $request, Irongate $irongate): RedirectResponse
    {
        $data = $request->validate([
            'material' => ['required', 'string', Rule::in([...Player::MATERIALS, ...Irongate::RARE])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        ['amount' => $amount, 'corn' => $corn] = $irongate->fence($request->user()->player, $data['material'], (int) $data['amount']);

        return $this->told("Ma Vetch took {$amount} {$data['material']} and paid you {$corn} Corn.");
    }

    public function tip(Request $request, Warrant $warrant, Irongate $irongate): RedirectResponse
    {
        $where = $irongate->tip($request->user()->player, $warrant);

        return $this->told("Wick leans in: \"{$warrant->wanted->name} is lying low in {$where}.\"");
    }
}
