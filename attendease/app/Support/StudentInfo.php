<?php

namespace App\Support;

/**
 * FR-ADM-10: a student's course (code from config/students.php), year level (1-4)
 * and section (a letter). Lists, display formats, and a forgiving parser for text
 * typed in other ways ("4TH YEAR", "Section 4-B", "Bachelor of Science Information Technology").
 */
class StudentInfo
{
    /** @return array<string, string> code => full name */
    public static function courses(): array
    {
        return collect(config('students.courses', []))->map(fn ($c) => $c['name'] ?? '')->all();
    }

    /** FR-ADM-19: a teacher's department, code => full name. */
    public static function departments(): array
    {
        return collect(config('students.departments', []))->map(fn ($d) => $d['name'] ?? '')->all();
    }

    /** @return list<int> */
    public static function yearLevels(): array
    {
        return array_map('intval', config('students.year_levels', [1, 2, 3, 4]));
    }

    /** @return list<string> */
    public static function sections(): array
    {
        return array_map(fn ($s) => strtoupper((string) $s), config('students.sections', []));
    }

    public static function ordinal(int $n): string
    {
        return $n.match (true) {
            in_array($n % 100, [11, 12, 13], true) => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };
    }

    /** "4th Year" */
    public static function yearLabel(?int $year): ?string
    {
        return $year ? self::ordinal($year).' Year' : null;
    }

    /** Full form shown under the name: "BSIT · 4th Year · Section B" (missing parts left out). */
    public static function full(?string $course, ?int $year, ?string $section): ?string
    {
        $parts = array_filter([$course, self::yearLabel($year), $section ? 'Section '.$section : null]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** Short form for badges and report columns: "BSIT 4-B", "4-B", "BSIT 4", or "Section B". */
    public static function compact(?string $course, ?int $year, ?string $section): ?string
    {
        if (! $course && ! $year) {
            return $section ? 'Section '.$section : null;
        }

        $yearSection = $year && $section ? $year.'-'.$section : ($year ? (string) $year : $section);

        return trim(($course ?? '').' '.($yearSection ?? '')) ?: null;
    }

    /* ------------------------------------------------------------------
     | Parsing typed text
     * ------------------------------------------------------------------ */

    private static function letters(string $text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }

    /** @return array<string, list<string>> code => every spelling that means it, longest first */
    private static function courseSpellings(string $list = 'courses'): array
    {
        return collect(config('students.'.$list, []))
            ->map(fn ($c, $code) => collect([$code, $c['name'] ?? '', ...($c['aliases'] ?? [])])
                ->filter()->unique()->sortByDesc(fn ($s) => strlen($s))->values()->all())
            ->all();
    }

    /**
     * @return array{value: ?string, error: ?string} the course code, null when blank
     */
    public static function parseCourse(mixed $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return ['value' => null, 'error' => null];
        }

        $typed = self::letters($text);
        foreach (self::courseSpellings() as $code => $spellings) {
            foreach ($spellings as $spelling) {
                $s = self::letters($spelling);
                if ($s !== '' && ($typed === $s || str_contains($typed, $s))) {
                    return ['value' => $code, 'error' => null];
                }
            }
        }

        return ['value' => null, 'error' => 'Course "'.$text.'" isn\'t in the list ('.implode(', ', array_keys(self::courses())).').'];
    }

    /**
     * FR-ADM-19: "Information Technology", "bs it" ... → "BSIT".
     *
     * @return array{value: ?string, error: ?string} the department code, null when blank
     */
    public static function parseDepartment(mixed $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return ['value' => null, 'error' => null];
        }

        $typed = self::letters($text);
        foreach (self::courseSpellings('departments') as $code => $spellings) {
            foreach ($spellings as $spelling) {
                $s = self::letters($spelling);
                if ($s !== '' && ($typed === $s || str_contains($typed, $s))) {
                    return ['value' => $code, 'error' => null];
                }
            }
        }

        return ['value' => null, 'error' => 'Department "'.$text.'" isn\'t in the list ('.implode(', ', array_keys(self::departments())).').'];
    }

    /**
     * Accepts 4, "4", "4th", "4TH YEAR", "Fourth Year", "IV".
     *
     * @return array{value: ?int, error: ?string}
     */
    public static function parseYear(mixed $text): array
    {
        if (is_int($text) || (is_float($text) && floor($text) === $text)) {
            $text = (string) (int) $text;
        }
        $raw = trim((string) $text);
        if ($raw === '') {
            return ['value' => null, 'error' => null];
        }

        $t = preg_replace('/\b(YEAR|YR|LEVEL)S?\b/', '', strtoupper($raw));
        $t = preg_replace('/[^A-Z0-9]/', '', $t);

        $year = match (true) {
            (bool) preg_match('/^(\d+)(ST|ND|RD|TH)?$/', $t, $m) => (int) $m[1],
            default => ['FIRST' => 1, 'SECOND' => 2, 'THIRD' => 3, 'FOURTH' => 4, 'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4][$t] ?? null,
        };

        if ($year === null || ! in_array($year, self::yearLevels(), true)) {
            return ['value' => null, 'error' => 'Year level "'.$raw.'" isn\'t valid (use '.self::yearLabel(min(self::yearLevels())).' to '.self::yearLabel(max(self::yearLevels())).').'];
        }

        return ['value' => $year, 'error' => null];
    }

    /**
     * Accepts "B", "Section B", "4B", "4-B", "Section 4-B", "BSIT-4B", "BSIT 4-B".
     * A year inside the section ("4-B") is returned too.
     *
     * @return array{section: ?string, year: ?int, course: ?string, error: ?string}
     */
    public static function parseSection(mixed $text): array
    {
        $raw = trim((string) $text);
        $result = ['section' => null, 'year' => null, 'course' => null, 'error' => null];
        if ($raw === '') {
            return $result;
        }

        $t = strtoupper($raw);
        foreach (self::courseSpellings() as $code => $spellings) {
            foreach ($spellings as $spelling) {
                $pattern = '/'.implode('[\s.\-_]*', array_map(fn ($ch) => preg_quote($ch, '/'), str_split(self::letters($spelling)))).'/';
                if (preg_match($pattern, $t)) {
                    $t = preg_replace($pattern, ' ', $t, 1);
                    $result['course'] = $code;
                    break 2;
                }
            }
        }
        $t = preg_replace('/\b(SECTION|SEC|YEAR|YR)\b\.?/', ' ', $t);
        $t = preg_replace('/[^A-Z0-9]/', '', $t);

        if (preg_match('/^([A-Z])$/', $t, $m)) {
            $result['section'] = $m[1];
        } elseif (preg_match('/^(\d)(?:ST|ND|RD|TH)?([A-Z])$/', $t, $m)) {
            [$result['year'], $result['section']] = [(int) $m[1], $m[2]];
        } else {
            $result['error'] = 'Section "'.$raw.'" isn\'t a section letter ('.implode(', ', self::sections()).').';

            return $result;
        }

        if ($result['year'] !== null && ! in_array($result['year'], self::yearLevels(), true)) {
            $result['error'] = 'Section "'.$raw.'" has year '.$result['year'].', which isn\'t a valid year level.';
        } elseif (! in_array($result['section'], self::sections(), true)) {
            $result['error'] = 'Section "'.$result['section'].'" isn\'t in the list ('.implode(', ', self::sections()).').';
        }

        return $result;
    }

    /**
     * Turns the three typed values into structured ones.
     *
     * @return array{course: ?string, year_level: ?int, section: ?string, problems: list<string>}
     */
    public static function convert(mixed $course, mixed $year, mixed $section): array
    {
        $c = self::parseCourse($course);
        $y = self::parseYear($year);
        $s = self::parseSection($section);

        $problems = array_values(array_filter([$c['error'], $y['error'], $s['error']]));

        if (! $c['error'] && ! $s['error'] && $c['value'] && $s['course'] && $c['value'] !== $s['course']) {
            $problems[] = 'Course "'.$course.'" doesn\'t match the course in section "'.$section.'".';
        }
        if (! $y['error'] && ! $s['error'] && $y['value'] && $s['year'] && $y['value'] !== $s['year']) {
            $problems[] = 'Year "'.$year.'" doesn\'t match the year in section "'.$section.'".';
        }

        return [
            'course' => $c['value'] ?? $s['course'],
            'year_level' => $y['value'] ?? $s['year'],
            'section' => $s['section'],
            'problems' => $problems,
        ];
    }
}
