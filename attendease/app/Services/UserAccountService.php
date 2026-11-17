<?php

namespace App\Services;

use App\Models\User;
use App\Rules\SchoolEmail;
use App\Support\StudentInfo;
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
    public function rules(?User $ignore = null, bool $requireStudentInfo = true): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'id_number' => ['required', 'string', 'max:50', Rule::unique('users', 'id_number')->ignore($ignore?->id)],
            // FR-ADM-13: optional; when given, a unique school address. Blank is stored as NULL, so it is never a duplicate.
            'email' => ['nullable', 'string', 'email', 'max:255', new SchoolEmail, Rule::unique('users', 'email')->ignore($ignore?->id)],
            ...$this->studentInfoRules($requireStudentInfo),
            'department' => $this->departmentRules($ignore),
        ];
    }

    /**
     * FR-ADM-10: course, year level and section come from the fixed lists in config/students.php.
     * With $requiredForStudents, a Student account must have all three (the role must be in the same input).
     */
    public function studentInfoRules(bool $requiredForStudents = true): array
    {
        $required = $requiredForStudents ? 'required_if:role,'.User::STUDENT : 'nullable';

        return [
            'course' => [$required, 'nullable', Rule::in(array_keys(StudentInfo::courses()))],
            'year_level' => [$required, 'nullable', 'integer', Rule::in(StudentInfo::yearLevels())],
            'section' => [$required, 'nullable', Rule::in(StudentInfo::sections())],
        ];
    }

    /**
     * FR-ADM-19: a department from the fixed list. A department typed before the list existed
     * may be kept as it is, so saving a form never wipes it.
     */
    public function departmentRules(?User $user = null): array
    {
        return ['nullable', 'string', 'max:255', Rule::in(array_filter([...array_keys(StudentInfo::departments()), $user?->department]))];
    }

    public const ATTRIBUTE_NAMES = ['id_number' => 'ID number', 'name' => 'full name', 'email' => 'email', 'year_level' => 'year level'];

    /** Trims text and lowercases the email, the same way sign-up does. */
    public function normalize(array $input): array
    {
        foreach (['name', 'id_number', 'email', 'course', 'year_level', 'section', 'department'] as $key) {
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
        if (isset($input['section'])) {
            $input['section'] = mb_strtoupper($input['section']);
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
