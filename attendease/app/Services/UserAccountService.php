<?php

namespace App\Services;

use App\Models\User;
use App\Rules\SchoolEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Shared by the admin's Add/Edit user forms and the CSV/Excel import (FR-ADM-06..09). */
class UserAccountService
{
    /**
     * The configured default password, or null when IMPORT_DEFAULT_PASSWORD is missing
     * or doesn't meet the password rules (it would be a weak shared password).
     */
    public function defaultPassword(): ?string
    {
        $password = (string) config('app.import_default_password');

        if ($password === '') {
            return null;
        }

        return Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes() ? $password : null;
    }

    /** Validation rules for the profile fields of an account (role and password are handled by the caller). */
    public function rules(?User $ignore = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'id_number' => ['required', 'string', 'max:50', Rule::unique('users', 'id_number')->ignore($ignore?->id)],
            'email' => ['required', 'string', 'email', 'max:255', new SchoolEmail, Rule::unique('users', 'email')->ignore($ignore?->id)],
            'course' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:255'],
        ];
    }

    public const ATTRIBUTE_NAMES = ['id_number' => 'ID number', 'name' => 'full name', 'email' => 'email'];

    /** Trims text and lowercases the email, the same way sign-up does. */
    public function normalize(array $input): array
    {
        foreach (['name', 'id_number', 'email', 'course', 'year', 'section', 'department'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $input[$key] = trim((string) $input[$key]);
                if ($input[$key] === '') {
                    $input[$key] = null;
                }
            }
        }
        if (isset($input['email'])) {
            $input['email'] = mb_strtolower($input['email']);
        }

        return $input;
    }

    /** FR-ADM-07: ends every open session of the user (database session driver). */
    public function endSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $user->forceFill(['remember_token' => null])->saveQuietly();
    }
}
