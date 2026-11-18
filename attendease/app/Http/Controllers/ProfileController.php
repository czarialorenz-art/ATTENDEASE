<?php

namespace App\Http\Controllers;

use App\Notifications\EmailChangedNotice;
use App\Notifications\SecurityAlert;
use App\Rules\SchoolEmail;
use App\Services\UserAccountService;
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
    public function update(Request $request, UserAccountService $accounts)
    {
        $user = $request->user();

        $request->merge([
            'email' => ($email = Str::lower(trim((string) $request->input('email')))) === '' ? null : $email,
            'id_number' => trim((string) $request->input('id_number')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // FR-ADM-13: optional (accounts without one log in with their ID number).
            'email' => ['nullable', 'string', 'email', 'max:255', new SchoolEmail, Rule::unique('users', 'email')->ignore($user->id)],
            'id_number' => ['required', 'string', 'max:50', Rule::unique('users', 'id_number')->ignore($user->id)],
            // FR-ADM-10: students pick course, year level and section from the fixed lists; others have a department.
            ...($user->isStudent()
                ? $accounts->studentInfoRules(requiredForStudents: false)
                : ['department' => $accounts->departmentRules($user)]),
        ], [], ['id_number' => 'ID number', 'year_level' => 'year level']);

        if ($user->isStudent()) {
            $picked = collect($data)->only(['course', 'year_level', 'section'])->filter(fn ($v) => filled($v));
            if ($picked->isEmpty() && $user->needsInfoUpdate()) {
                // Nothing picked yet: keep the old typed values so the admin can still see them.
                $data = collect($data)->except(['course', 'year_level', 'section'])->all();
            } else {
                $user->year = null; // the old free-text year is replaced by year_level
            }
        }

        $oldEmail = $user->email;
        $labels = ['name' => 'Name', 'email' => 'Email', 'id_number' => 'ID number', 'course' => 'Course', 'year_level' => 'Year level', 'section' => 'Section', 'department' => 'Department'];
        $before = $user->only(array_keys($labels));
        $user->update($data);
        if ($changes = \App\Support\Audit::diff($before, $user, $labels)) {
            \App\Support\Audit::log(\App\Support\Audit::PROFILE_UPDATED, \App\Support\Audit::who($user).' updated their profile: '.$changes, $user);
        }

        if ($oldEmail !== $user->email && filled($oldEmail)) {
            Notification::route('mail', $oldEmail)->notify(new SecurityAlert(
                SecurityAlert::EMAIL_CHANGED, RequestContext::capture($request), $user->name, $user->email,
            ));
            $user->notify(new EmailChangedNotice);
        }

        return back()->with('status', 'Profile saved.');
    }
}
