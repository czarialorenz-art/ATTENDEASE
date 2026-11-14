<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\SecurityAlert;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function edit()
    {
        return view('account.password');
    }

    /** FR-AUTH-13/14 */
    public function update(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        $user->update(['password' => $data['password']]); // hashed by the model cast

        // Re-hashes the (new) password so other sessions' stored hash no longer matches.
        Auth::logoutOtherDevices($data['password']);

        // With the database session driver, also drop the other session rows outright.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED, RequestContext::capture($request)));

        return back()->with('status', 'Password changed. Your other sessions have been signed out.');
    }
}
