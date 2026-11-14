<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Rules\SchoolEmail;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function create()
    {
        return view('auth.register');
    }

    /** FR-AUTH-01..05 */
    public function store(Request $request, DeviceService $devices)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'id_number' => trim((string) $request->input('id_number')),
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'id_number' => ['required', 'string', 'max:50', 'unique:users,id_number'],
            'email' => ['required', 'string', 'email', 'max:255', new SchoolEmail, 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ], [], [
            'name' => 'full name',
            'id_number' => 'ID number',
            'email' => 'school email',
        ]);

        $user = User::create($data);

        Auth::login($user);
        $request->session()->regenerate();

        // Remember this browser so the first login here isn't reported as a new device.
        $devices->touch($user, $request);

        $user->notify(new WelcomeNotification);

        return redirect()->route('dashboard')->with('status', 'Welcome, '.$user->name.'! Your account is ready.');
    }
}
