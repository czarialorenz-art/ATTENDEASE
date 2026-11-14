<?php

namespace App\Http\Controllers;

use App\Notifications\EmailChangedNotice;
use App\Notifications\SecurityAlert;
use App\Rules\SchoolEmail;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('account.profile', ['user' => $request->user()]);
    }

    /** FR-AUTH-15..17 */
    public function update(Request $request)
    {
        $user = $request->user();

        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'id_number' => trim((string) $request->input('id_number')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new SchoolEmail, Rule::unique('users', 'email')->ignore($user->id)],
            'id_number' => ['required', 'string', 'max:50', Rule::unique('users', 'id_number')->ignore($user->id)],
            'course' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:255'],
        ], [], ['id_number' => 'ID number']);

        $oldEmail = $user->email;
        $user->update($data);

        if ($oldEmail !== $user->email) {
            Notification::route('mail', $oldEmail)->notify(new SecurityAlert(
                SecurityAlert::EMAIL_CHANGED, RequestContext::capture($request), $user->name, $user->email,
            ));
            $user->notify(new EmailChangedNotice);
        }

        return back()->with('status', 'Profile saved.');
    }
}
