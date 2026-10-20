<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * An owner playing as a player, to see a bug as they see it. The owner's
 * own account is remembered, a banner shows on every page, and one tap
 * goes back. Owners can't be played as.
 */
class ImpersonateController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is_admin, 403, "Owners can't be played as.");

        $admin = $request->user();
        Log::info('Owner playing as a player', ['admin' => $admin->id, 'user' => $user->id]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);

        return redirect()->route('play');
    }

    /** Back to the owner's own account. */
    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);
        $admin = $adminId !== null ? User::query()->where('is_admin', true)->find($adminId) : null;
        abort_if($admin === null, 403);

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.bugs');
    }
}
