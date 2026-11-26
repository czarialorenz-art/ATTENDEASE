<?php

namespace App\Services;

use App\Imports\ClassListImport;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * FR-CLS-16: checks a teacher's class list (Name, Section) against who has joined the class.
 * Read only: no accounts, enrollments or notifications are created.
 */
class ClassListCheckService
{
    public const JOINED = 'joined';
    public const PENDING = 'pending';
    public const NOT_JOINED = 'not_joined';
    public const POSSIBLE = 'possible';

    /** Result groups, in the order shown. */
    public const GROUPS = [
        self::JOINED => 'Joined',
        self::PENDING => 'Pending request',
        self::NOT_JOINED => 'Not joined',
        self::POSSIBLE => 'Possible match',
    ];

    public const MAX_ROWS = 1000;

    /** Accepted headings (compared after UserImportService::normalizeHeading). */
    private const ALIASES = [
        'name' => ['name', 'names', 'full name', 'fullname', 'student name', 'student', 'name of student', 'student full name', 'complete name'],
        'section' => ['section', 'sec', 'year section', 'yr section', 'yr & sec', 'year & section', 'year and section', 'yr and sec', 'class section', 'course year section', 'course & section'],
    ];

    /**
     * Every sheet of the file is checked (FR-CLS-16): several sections can be in one file, mixed in
     * one sheet or one sheet each. The Section column is optional: without it, a sheet named like a
     * section ("4-B", "BSIT 4-A", "Section B") gives the section, otherwise rows match by name only.
     *
     * @return array{file: string, rows: int, sheets: list<string>, sections: array<string, int>, groups: array<string, list<array{row: int, sheet: ?string, name: string, section: string, id_number: ?string, student_section: ?string}>>, missing_name: list<string>}
     */
    public function check(SchoolClass $class, UploadedFile $file): array
    {
        $import = new ClassListImport;
        Excel::import($import, $file);
        $filled = fn ($cells) => collect($cells)->contains(fn ($c) => trim((string) $c) !== '');
        $sheets = collect($import->sheets)->filter(fn ($s) => $s['rows']->contains($filled))->values();

        if ($sheets->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'The file is empty. Download the template and fill it in.']);
        }

        // Sheets without a Name column (e.g. an instructions sheet) are skipped when another sheet has one.
        $parsed = [];
        $firstError = null;
        foreach ($sheets as $sheet) {
            try {
                [$map, $rows] = $this->readSheet($sheet['rows']);
                $parsed[] = ['title' => $sheet['title'], 'map' => $map, 'rows' => $rows];
            } catch (ValidationException $e) {
                $firstError ??= $e;
            }
        }
        if ($parsed === []) {
            throw $firstError;
        }
        if (($total = collect($parsed)->sum(fn ($p) => $p['rows']->count())) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.number_format($total).' rows. Check at most '.number_format(self::MAX_ROWS).' at a time.']);
        }
        $multi = count($parsed) > 1;

        // Candidates: this class's approved and pending students.
        $enrollments = $class->enrollments()
            ->whereIn('status', [Enrollment::APPROVED, Enrollment::PENDING])
            ->with('student')->get()
            ->map(fn ($e) => ['enrollment' => $e, 'tokens' => self::nameTokens($e->student->name)]);

        $groups = array_fill_keys(array_keys(self::GROUPS), []);
        $missingName = [];
        $sections = [];
        $count = 0;

        foreach ($parsed as ['title' => $title, 'map' => $map, 'rows' => $rows]) {
            $sheetSection = isset($map['section']) ? null : self::sectionFromTitle($title);

            foreach ($rows as [$line, $cells]) {
                $name = trim((string) ($cells[$map['name']] ?? ''));
                $section = isset($map['section']) ? trim((string) ($cells[$map['section']] ?? '')) : ($sheetSection ?? '');

                if ($name === '' && (isset($map['section']) ? $section === '' : true)) {
                    continue; // empty row
                }
                $count++;
                if ($name === '') {
                    $missingName[] = ($multi ? $title.' ' : '').'row '.$line;
                    continue;
                }
                $key = Str::upper($section);
                $sections[$key] = ($sections[$key] ?? 0) + 1;

                $tokens = self::nameTokens($name);
                $byName = $enrollments->filter(fn ($c) => self::sameName($tokens, $c['tokens']));
                $bySection = $byName->filter(fn ($c) => self::sameSection($section, $c['enrollment']->student));

                $entry = ['row' => $line, 'sheet' => $multi ? $title : null, 'name' => $name, 'section' => $section, 'id_number' => null, 'student_section' => null];

                // Joined first, then pending, among the name + section matches.
                $hit = $bySection->sortBy(fn ($c) => $c['enrollment']->status === Enrollment::APPROVED ? 0 : 1)->first();
                if ($hit) {
                    $group = $hit['enrollment']->status === Enrollment::APPROVED ? self::JOINED : self::PENDING;
                    $groups[$group][] = [...$entry, 'id_number' => $hit['enrollment']->student->id_number, 'student_section' => $hit['enrollment']->student->sectionCode()];
                } elseif ($byName->isNotEmpty()) {
                    $student = $byName->first()['enrollment']->student;
                    $groups[self::POSSIBLE][] = [...$entry, 'id_number' => $student->id_number, 'student_section' => $student->sectionCode()];
                } else {
                    $groups[self::NOT_JOINED][] = $entry;
                }
            }
        }

        if ($count === 0) {
            throw ValidationException::withMessages(['file' => 'The file has no data rows. Fill in the template below the heading row.']);
        }
        uksort($sections, fn ($a, $b) => [$a === '', $a] <=> [$b === '', $b]); // "no section" last

        return [
            'file' => $file->getClientOriginalName(),
            'rows' => $count,
            'sheets' => collect($parsed)->pluck('title')->all(),
            'sections' => $sections,
            'groups' => $groups,
            'missing_name' => $missingName,
        ];
    }

    /** A sheet named like a section ("4-B", "BSIT 4-A", "Section B", "B") gives its rows' section. "Sheet1" doesn't. */
    public static function sectionFromTitle(string $title): ?string
    {
        $t = trim($title);

        return preg_match('/^(?:section\s+)?(?:[a-z]{2,6}\s*)?(?:\d\s*-?\s*)?[a-d]$/i', $t) ? $t : null;
    }

    /**
     * The heading row is searched for in the first rows (like the admin import, FR-ADM-18).
     * Name is required; Section is optional.
     *
     * @return array{0: array<string, int>, 1: Collection<int, array{0: int, 1: array}>} field => column, [line number, cells]
     */
    private function readSheet(Collection $sheet): array
    {
        $rows = $sheet->map(fn ($r) => array_values(collect($r)->all()))->values();
        $lookup = collect(self::ALIASES)->flatMap(fn ($aliases, $field) => collect($aliases)
            ->mapWithKeys(fn ($a) => [UserImportService::normalizeHeading($a) => $field]))->all();

        $best = null;
        foreach ($rows->take(UserImportService::HEADING_SEARCH_ROWS) as $index => $cells) {
            $map = [];
            foreach ($cells as $col => $heading) {
                $field = $lookup[UserImportService::normalizeHeading($heading)] ?? null;
                if ($field && ! isset($map[$field])) {
                    $map[$field] = $col;
                }
            }
            // The row with Name (and Section, if any) wins; the first such row is the heading row.
            $score = [isset($map['name']) ? 1 : 0, count($map)];
            if ($best === null || $score > $best['score']) {
                $best = ['index' => $index, 'map' => $map, 'score' => $score];
            }
            if (count($map) === 2) {
                break;
            }
        }

        if ($best === null) {
            throw ValidationException::withMessages(['file' => 'The file is empty. Download the template and fill it in.']);
        }
        if (! isset($best['map']['name'])) {
            $found = collect($rows[$best['index']] ?? [])->map(fn ($h) => trim((string) $h))->filter()->map(fn ($h) => '"'.$h.'"');
            throw ValidationException::withMessages(['file' => 'Missing the "Name" column. Found in your file: '
                .($found->isEmpty() ? 'no headings' : $found->implode(', ')).'. Expected: Name (required), Section (optional).']);
        }

        $data = $rows->slice($best['index'] + 1)->map(fn ($cells, $i) => [$i + 1, $cells])->values();

        return [$best['map'], $data];
    }

    /** "Loreno, Maria Teresa A." → ["a", "loreno", "maria", "teresa"]: case, periods, commas and spaces ignored. */
    public static function nameTokens(string $name): array
    {
        $n = Str::lower(Str::ascii($name));
        $n = preg_replace('/[.,]/', ' ', $n);
        $tokens = preg_split('/\s+/', trim($n), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return $tokens;
    }

    /**
     * Same words in any order ("Surname, Given" or "Given Surname"). A middle initial on only one side
     * still matches ("Maria Teresa Loreno" = "Maria Teresa A. Loreno").
     */
    public static function sameName(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
        $extra = $long;
        foreach ($short as $t) {
            $k = array_search($t, $extra, true);
            if ($k === false) {
                return false;
            }
            unset($extra[$k]);
        }

        return count($short) >= 2 && collect($extra)->every(fn ($t) => mb_strlen($t) === 1); // only initials left over
    }

    /** "4-B", "BSIT 4-B", "4B", "B" all match a student in BSIT 4-B. A blank section matches by name only. */
    public static function sameSection(string $section, User $student): bool
    {
        $norm = fn ($s) => preg_replace('/[^A-Z0-9]/', '', Str::upper((string) $s));
        $given = $norm(preg_replace('/^\s*section\s*/i', '', $section));
        if ($given === '') {
            return true;
        }

        $letter = $norm($student->section);
        $candidates = array_filter([
            $norm($student->sectionCode()),                              // BSIT4B
            $student->year_level && $letter ? $student->year_level.$letter : null, // 4B
            $letter,                                                     // B
        ]);

        return in_array($given, $candidates, true);
    }
}
