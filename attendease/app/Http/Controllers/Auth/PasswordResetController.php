<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Support\RequestContext;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public const GENERIC = 'If an account exists for that email, we have sent a password reset link.';

    public function request()
    {
        return view('auth.forgot-password');
    }

    /** FR-AUTH-10: always the same response. */
    public function email(Request $request)
    {
        $request->validate(['email' => ['required', 'string', 'max:255']]);

        Password::sendResetLink(['email' => Str::lower(trim((string) $request->input('email')))]);

        return back()->with('status', self::GENERIC);
    }

    public function edit(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    /** FR-AUTH-11/12 */
    public function update(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $context = RequestContext::capture($request);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($context) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Log out every session the user has (database session driver).
                DB::table('sessions')->where('user_id', $user->id)->delete();

                event(new PasswordReset($user));

                $user->notify(new SecurityAlert(SecurityAlert::PASSWORD_CHANGED, $context));
                \App\Support\Audit::log(\App\Support\Audit::PASSWORD_CHANGED, \App\Support\Audit::who($user).' reset their password with an emailed link', $user, [], $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This password reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Please log in.');
    }
}
