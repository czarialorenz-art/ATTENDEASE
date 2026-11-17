<?php

namespace App\Services;

use App\Imports\UsersImport;
use App\Models\User;
use App\Rules\SchoolEmail;
use App\Support\Audit;
use App\Support\StudentInfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * FR-ADM-09: imports Students or Teachers (chosen on the page, not per row) from CSV/Excel.
 * Each row is checked on its own; invalid rows and duplicates (already in the system, or
 * repeated in the file) are skipped with the reason, and the rest are saved with the hashed
 * default password.
 */
class UserImportService
{
    public const MAX_ROWS = 2000;

    public const TYPES = [User::STUDENT => 'Student', User::TEACHER => 'Teacher'];

    /**
     * FR-ADM-14: the columns of each import type, in template order: field => [heading, required].
     *
     * @var array<string, array<string, array{0: string, 1: bool}>>
     */
    public const COLUMNS = [
        User::STUDENT => [
            'id_number' => ['ID Number', true],
            'name' => ['Name', true],
            'email' => ['Email', false],
        ],
        User::TEACHER => [
            'id_number' => ['ID Number', true],
            'name' => ['Name', true],
            'email' => ['Email', false],
            'department' => ['Department', false],
        ],
    ];

    /**
     * FR-ADM-15: headings that mean each field, compared after normalizing (no case, dots,
     * underscores, hyphens, asterisks, brackets or extra spaces): "ID No." = "id_no" = "ID NO".
     */
    /**
     * Optional columns that aren't in the template but are still read (and checked) when a file has them.
     * A student's course, year level and section are optional and usually set with the page's dropdowns.
     */
    public const EXTRA_COLUMNS = [
        User::STUDENT => ['course', 'year_level', 'section'],
        User::TEACHER => [],
    ];

    public const HEADING_ALIASES = [
        'id_number' => ['id number', 'id no', 'id num', 'id #', 'id', 'id code', 'student id', 'student id number', 'student id no',
            'student no', 'student number', 'student num', 'teacher id', 'employee id', 'employee no', 'employee number', 'school id', 'id nos'],
        'name' => ['name', 'full name', 'fullname', 'student name', 'teacher name', 'complete name', 'name of student', 'name of teacher'],
        'email' => ['email', 'e mail', 'email address', 'e mail address', 'school email', 'school email address'],
        'course' => ['course', 'program', 'course code', 'degree', 'course program'],
        'year_level' => ['year level', 'year', 'yr level', 'yr', 'year lvl', 'yr lvl', 'level', 'grade level'],
        'section' => ['section', 'sec', 'section letter'],
        'department' => ['department', 'dept', 'college', 'office'],
    ];

    public function __construct(private UserAccountService $accounts) {}

    /** "ID No." / "id_no" / " ID  NO * " → "id no" */
    public static function normalizeHeading(mixed $heading): string
    {
        $h = mb_strtolower((string) $heading);
        $h = str_replace("\u{FEFF}", '', $h);                 // byte-order mark from some CSV files
        $h = preg_replace('/\(.*?\)|\[.*?\]/', ' ', $h);     // "Email (optional)"
        $h = str_replace(['.', '*', ':'], '', $h);
        $h = str_replace(['_', '-', '/'], ' ', $h);

        return trim(preg_replace('/\s+/', ' ', $h));
    }

    /** The heading text for the page and the template, e.g. "ID Number*". */
    public static function headings(string $type, bool $markRequired = false): array
    {
        return collect(self::COLUMNS[$type])->map(fn ($c) => $c[0].($markRequired && $c[1] ? '*' : ''))->values()->all();
    }

    /**
     * @param  array{course?: ?string, year_level?: ?int, section?: ?string}  $fixed  FR-ADM-16: values chosen on the page for every
     *         student row (the file's column is then not needed); null = read the column
     * @return array{type: string, imported: int, skipped: list<array{row: int, id_number: ?string, reasons: list<string>}>, email_alerts: list<array{row: int, id_number: string, name: string, problem: string}>, fixed: array, file: string}
     */
    public function import(UploadedFile $file, string $type, array $fixed = []): array
    {
        $fixed = $type === User::STUDENT ? array_filter($fixed, fn ($v) => filled($v)) : [];
        $default = $this->accounts->defaultPassword();
        if ($default === null) {
            throw ValidationException::withMessages(['file' => 'Set IMPORT_DEFAULT_PASSWORD in .env first (8+ characters with upper and lower case letters, a number and a symbol). Imported accounts get that password.']);
        }

        $import = new UsersImport;
        Excel::import($import, $file);
        [$headerLine, $map, $rows] = $this->readSheet($import->rows, $type, array_keys($fixed));

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.number_format($rows->count()).' rows. Import at most '.number_format(self::MAX_ROWS).' at a time.']);
        }

        $hash = Hash::make($default); // hashed once and shared, instead of once per row
        $seenIds = [];
        $seenEmails = [];
        $skipped = [];
        $emailAlerts = [];
        $created = collect();

        DB::transaction(function () use ($rows, $map, $type, $fixed, $headerLine, $hash, &$seenIds, &$seenEmails, &$skipped, &$emailAlerts, $created) {
            foreach ($rows as $offset => $cells) {
                $line = $headerLine + 1 + $offset;
                [$data, $infoProblems] = $this->rowData($cells, $map, $type, $fixed);

                if (collect($cells)->every(fn ($c) => trim((string) $c) === '')) {
                    continue; // blank line (checked on the file's cells, not the values chosen on the page)
                }

                // FR-ADM-17: an email that isn't a valid @school address doesn't stop the import: the account
                // is saved without it and listed in the email alert.
                $emailProblem = null;
                if ($data['email'] !== null && Validator::make(['email' => $data['email']], ['email' => ['email', 'max:255', new SchoolEmail]])->fails()) {
                    $emailProblem = '"'.$data['email'].'" isn\'t a valid @'.config('app.allowed_email_domain').' address, so it was left out.';
                    $data['email'] = null;
                }

                $reasons = [...$infoProblems, ...$this->problems($data, $seenIds, $seenEmails)];

                if ($reasons) {
                    $skipped[] = ['row' => $line, 'id_number' => $data['id_number'], 'reasons' => $reasons];

                    continue;
                }

                $user = new User(collect($data)->except('role')->all());
                $user->password = $hash;
                $user->role = $type;
                $user->save();

                $seenIds[mb_strtolower($data['id_number'])] = $line;
                if ($data['email'] !== null) {
                    $seenEmails[$data['email']] = $line; // blank emails are never duplicates
                }
                $created->push($user);

                if ($data['email'] === null) {
                    $emailAlerts[] = ['row' => $line, 'id_number' => $user->id_number, 'name' => $user->name,
                        'problem' => $emailProblem ?? 'No email in the file.'];
                }
            }
        });

        $label = self::TYPES[$type];
        $result = [
            'type' => $type,
            'imported' => $created->count(),
            'skipped' => $skipped,
            'email_alerts' => $emailAlerts,
            'fixed' => $fixed,
            'file' => $file->getClientOriginalName(),
        ];

        // FR-LOG-02: one entry per import, with the type and the counts.
        Audit::log(Audit::USERS_IMPORTED,
            $label.' import from '.$result['file'].': '.$result['imported'].' '.str(mb_strtolower($label))->plural($result['imported']).' imported, '
                .count($skipped).' '.str('row')->plural(count($skipped)).' skipped'
                .($emailAlerts ? '; '.count($emailAlerts).' without a school email' : '')
                .($fixed ? ' (set for every row: '.StudentInfo::full($fixed['course'] ?? null, $fixed['year_level'] ?? null, $fixed['section'] ?? null).')' : ''),
            null,
            [
                'type' => $type,
                'file' => $result['file'],
                'imported' => $result['imported'],
                'skipped' => count($skipped),
                'user_ids' => $created->pluck('id')->all(),
                'skipped_rows' => array_slice($skipped, 0, 200),
                'without_school_email' => count($emailAlerts),
                'fixed' => $fixed,
            ],
        );

        return $result;
    }

    /** How many rows from the top are searched for the heading row (FR-ADM-18). */
    public const HEADING_SEARCH_ROWS = 20;

    /**
     * Which column holds each field, for one candidate heading row.
     *
     * @return array<string, int> field => column index
     */
    private function mapHeadings(array $headings, string $type): array
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = [];
            foreach (self::HEADING_ALIASES as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $lookup[self::normalizeHeading($alias)] = $field;
                }
            }
        }

        $map = [];
        foreach ($headings as $i => $heading) {
            $field = $lookup[self::normalizeHeading($heading)] ?? null;
            if ($field && (array_key_exists($field, self::COLUMNS[$type]) || in_array($field, self::EXTRA_COLUMNS[$type], true)) && ! isset($map[$field])) {
                $map[$field] = $i; // the first matching column wins
            }
        }

        return $map;
    }

    /**
     * FR-ADM-18: finds the heading row automatically. Titles, dates or school names above it are
     * skipped: of the first rows, the one with the most required headings (then the most known
     * headings) wins; ties go to the higher row.
     *
     * @return array{0: int, 1: array<string, int>, 2: Collection<int, array>} heading line, field => column index, data rows
     */
    private function readSheet(Collection $sheet, string $type, array $fixedFields = []): array
    {
        $rows = $sheet->map(fn ($r) => array_values(collect($r)->all()))->values();
        $filled = fn ($cells) => collect($cells)->contains(fn ($c) => trim((string) $c) !== '');

        if ($rows->search($filled) === false) {
            throw ValidationException::withMessages(['file' => 'The file is empty. Download the '.self::TYPES[$type].' template and fill it in.']);
        }

        // A column whose value was chosen on the page (FR-ADM-16) isn't needed in the file.
        $required = collect(self::COLUMNS[$type])->filter(fn ($c, $field) => $c[1] && ! in_array($field, $fixedFields, true));

        $best = null;
        foreach ($rows->take(self::HEADING_SEARCH_ROWS) as $index => $cells) {
            if (! $filled($cells)) {
                continue;
            }
            $map = $this->mapHeadings($cells, $type);
            $score = [$required->keys()->filter(fn ($f) => isset($map[$f]))->count(), count($map)];
            if ($best === null || $score > $best['score']) {
                $best = ['index' => $index, 'map' => $map, 'score' => $score];
            }
            if ($score[0] === $required->count() && $required->isNotEmpty()) {
                break; // every required heading found: this is the heading row
            }
        }

        // Nothing recognizable at all: report the first non-empty row.
        if ($best['score'] === [0, 0]) {
            $best['index'] = $rows->search($filled);
        }
        $headerIndex = $best['index'];
        $map = $best['map'];

        $missing = $required->filter(fn ($c, $field) => ! isset($map[$field]))->map(fn ($c) => $c[0]);
        if ($missing->isNotEmpty()) {
            $found = collect($rows[$headerIndex])->map(fn ($h) => trim((string) $h))->filter(fn ($h) => $h !== '');
            throw ValidationException::withMessages(['file' => 'Missing required '.str('column')->plural($missing->count()).': '
                .$missing->map(fn ($m) => '"'.$m.'"')->implode(', ').'. '
                .'Found in your file'.($headerIndex > 0 ? ' (row '.($headerIndex + 1).')' : '').': '
                .($found->isEmpty() ? 'no headings' : $found->map(fn ($h) => '"'.$h.'"')->implode(', ')).'. '
                .'Expected for a '.self::TYPES[$type].' import: '.implode(', ', self::headings($type, true)).' (* = required).'
                .($best['score'][1] === 0 ? ' No heading row was found in the first '.self::HEADING_SEARCH_ROWS.' rows.' : '')]);
        }

        $data = $rows->slice($headerIndex + 1)->values();
        if ($data->filter(fn ($cells) => collect($cells)->contains(fn ($c) => trim((string) $c) !== ''))->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows. Fill in the template below the heading row.']);
        }

        return [$headerIndex + 1, $map, $data];
    }

    /**
     * The row's values for this import type. For students, course / year level / section are
     * turned into the fixed values (FR-ADM-10: "4th Year", "Section 4-B" and "bs it" are understood).
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function rowData(array $cells, array $map, string $type, array $fixed = []): array
    {
        $data = ['role' => $type];
        foreach (['id_number', 'name', 'email', 'course', 'year_level', 'section', 'department'] as $field) {
            $value = $fixed[$field] ?? (isset($map[$field]) ? ($cells[$map[$field]] ?? null) : null);
            // Excel may hand a number back as 20230001.0; keep it as written.
            if (is_float($value) && floor($value) === $value) {
                $value = (string) (int) $value;
            }
            $data[$field] = $value;
        }

        $typed = ['course' => $data['course'], 'year_level' => $data['year_level'], 'section' => $data['section']];
        $data = $this->accounts->normalize($data);
        $data['course'] = $data['year_level'] = $data['section'] = null;
        $problems = [];

        if ($type === User::STUDENT) {
            $data['department'] = null;
            $info = StudentInfo::convert($typed['course'], $typed['year_level'], $typed['section']);
            $problems = $info['problems'];
            if (! $problems) {
                [$data['course'], $data['year_level'], $data['section']] = [$info['course'], $info['year_level'], $info['section']];
            } else {
                $data['course'] = $data['year_level'] = $data['section'] = 'invalid'; // already explained; skip the "required" message
            }
        }

        return [$data, $problems];
    }

    /** @return list<string> the reasons this row can't be imported (empty = OK) */
    private function problems(array $data, array $seenIds, array $seenEmails): array
    {
        $reasons = [];

        if ($data['id_number'] !== null && isset($seenIds[mb_strtolower($data['id_number'])])) {
            $reasons[] = 'Duplicate ID number: same as row '.$seenIds[mb_strtolower($data['id_number'])].' of this file.';
        }
        if ($data['email'] !== null && isset($seenEmails[$data['email']])) {
            $reasons[] = 'Duplicate email: same as row '.$seenEmails[$data['email']].' of this file.';
        }

        // Course, year level and section are optional in an import, but must be valid when given.
        $validator = Validator::make($data, $this->accounts->rules(requireStudentInfo: false), [
            'id_number.required' => 'ID Number is required.',
            'name.required' => 'Name is required.',
            'id_number.unique' => 'ID number :input already belongs to an account.',
            'email.unique' => 'Email :input already belongs to an account.',
            'course.required_if' => 'Course is required for students.',
            'year_level.required_if' => 'Year Level is required for students.',
            'section.required_if' => 'Section is required for students.',
        ], UserAccountService::ATTRIBUTE_NAMES);

        $errors = $validator->errors();
        foreach (['course', 'year_level', 'section'] as $field) {
            if ($data[$field] === 'invalid') {
                $errors->forget($field); // the parser already said what's wrong
            }
        }
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
