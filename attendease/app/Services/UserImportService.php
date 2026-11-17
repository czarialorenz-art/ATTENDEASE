<?php

namespace App\Services;

use App\Imports\UsersImport;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * FR-ADM-09: imports Students and Teachers from CSV/Excel. Each row is checked on its own;
 * invalid rows and duplicates (already in the system, or repeated in the file) are skipped
 * with the reason, and the rest are saved with the hashed default password.
 */
class UserImportService
{
    public const MAX_ROWS = 2000;

    /** Columns every file must have (headings are matched without case or spacing). */
    public const REQUIRED_COLUMNS = ['role', 'id_number', 'name', 'email'];

    public const OPTIONAL_COLUMNS = ['course', 'year', 'section', 'department'];

    public const IMPORTABLE_ROLES = [User::STUDENT, User::TEACHER];

    public function __construct(private UserAccountService $accounts) {}

    /**
     * @return array{imported: int, students: int, teachers: int, skipped: list<array{row: int, id_number: ?string, reasons: list<string>}>, file: string}
     */
    public function import(UploadedFile $file): array
    {
        $default = $this->accounts->defaultPassword();
        if ($default === null) {
            throw ValidationException::withMessages(['file' => 'Set IMPORT_DEFAULT_PASSWORD in .env first (8+ characters with upper and lower case letters, a number and a symbol). Imported accounts get that password.']);
        }

        $import = new UsersImport;
        Excel::import($import, $file);
        $rows = $import->rows;

        $this->checkColumns($rows);

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.number_format($rows->count()).' rows. Import at most '.number_format(self::MAX_ROWS).' at a time.']);
        }

        $hash = Hash::make($default); // hashed once and shared, instead of once per row
        $seenIds = [];
        $seenEmails = [];
        $skipped = [];
        $created = collect();

        DB::transaction(function () use ($rows, $hash, &$seenIds, &$seenEmails, &$skipped, $created) {
            foreach ($rows as $index => $row) {
                $line = $index + 2; // row 1 is the headings
                $data = $this->rowData($row);

                if (collect($data)->filter(fn ($v) => $v !== null)->isEmpty()) {
                    continue; // blank line
                }

                $reasons = $this->problems($data, $seenIds, $seenEmails);

                if ($reasons) {
                    $skipped[] = ['row' => $line, 'id_number' => $data['id_number'], 'reasons' => $reasons];

                    continue;
                }

                $user = new User(collect($data)->except('role')->all());
                $user->password = $hash;
                $user->role = $data['role'];
                $user->save();

                $seenIds[mb_strtolower($data['id_number'])] = $line;
                $seenEmails[$data['email']] = $line;
                $created->push($user);
            }
        });

        $result = [
            'imported' => $created->count(),
            'students' => $created->where('role', User::STUDENT)->count(),
            'teachers' => $created->where('role', User::TEACHER)->count(),
            'skipped' => $skipped,
            'file' => $file->getClientOriginalName(),
        ];

        Audit::log(Audit::USERS_IMPORTED,
            'Imported '.$result['imported'].' '.str('user')->plural($result['imported'])
                .' ('.$result['students'].' '.str('student')->plural($result['students']).', '.$result['teachers'].' '.str('teacher')->plural($result['teachers']).')'
                .' from '.$result['file'].'; '.count($skipped).' '.str('row')->plural(count($skipped)).' skipped',
            null,
            [
                'file' => $result['file'],
                'imported' => $result['imported'],
                'skipped' => count($skipped),
                'user_ids' => $created->pluck('id')->all(),
                'skipped_rows' => array_slice($skipped, 0, 200),
            ],
        );

        return $result;
    }

    private function checkColumns(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows. Fill in the template below the heading row.']);
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($rows->first()->all()));
        if ($missing) {
            $names = collect($missing)->map(fn ($c) => '"'.str_replace('_', ' ', ucwords($c, '_')).'"')->implode(', ');

            throw ValidationException::withMessages(['file' => 'Missing column '.$names.'. Download the template and keep its heading row.']);
        }
    }

    /** @return array<string, ?string> */
    private function rowData(Collection $row): array
    {
        $data = [];
        foreach ([...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS] as $column) {
            $value = $row->get($column);
            // Excel may hand a number back as 20230001.0; keep it as written.
            if (is_float($value) && floor($value) === $value) {
                $value = (string) (int) $value;
            }
            $data[$column] = $value;
        }

        $data = $this->accounts->normalize($data);
        $data['role'] = $data['role'] !== null ? mb_strtolower(trim((string) $data['role'])) : null;

        return $data;
    }

    /** @return list<string> the reasons this row can't be imported (empty = OK) */
    private function problems(array $data, array $seenIds, array $seenEmails): array
    {
        $reasons = [];

        if (! in_array($data['role'], self::IMPORTABLE_ROLES, true)) {
            $reasons[] = $data['role'] === null
                ? 'Role is required (Student or Teacher).'
                : 'Role "'.$data['role'].'" isn\'t allowed. Use Student or Teacher.';
        }

        if ($data['id_number'] !== null && isset($seenIds[mb_strtolower($data['id_number'])])) {
            $reasons[] = 'Duplicate ID number: same as row '.$seenIds[mb_strtolower($data['id_number'])].' of this file.';
        }
        if ($data['email'] !== null && isset($seenEmails[$data['email']])) {
            $reasons[] = 'Duplicate email: same as row '.$seenEmails[$data['email']].' of this file.';
        }

        $validator = Validator::make($data, $this->accounts->rules(), [
            'id_number.unique' => 'ID number :input already belongs to an account.',
            'email.unique' => 'Email :input already belongs to an account.',
        ], UserAccountService::ATTRIBUTE_NAMES);

        $errors = $validator->errors();
        // A repeat inside the file is already explained; don't also say the (just imported) row "already belongs to an account".
        foreach (['id_number' => $seenIds, 'email' => $seenEmails] as $field => $seen) {
            $key = $field === 'id_number' ? mb_strtolower((string) $data[$field]) : $data[$field];
            if ($data[$field] !== null && isset($seen[$key])) {
                $errors->forget($field);
            }
        }

        return [...$reasons, ...$errors->all()];
    }
}
