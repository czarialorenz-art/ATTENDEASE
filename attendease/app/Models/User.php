<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Support\StudentInfo;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ADMIN = 'admin';
    public const TEACHER = 'teacher';
    public const STUDENT = 'student';

    /** FR-ADM-01: every account has exactly one role. */
    public const ROLES = [self::ADMIN => 'Admin', self::TEACHER => 'Teacher', self::STUDENT => 'Student'];

    /**
     * role and deactivated_at are left out on purpose: only the admin side sets them,
     * explicitly, so no self-service form can ever change them.
     */
    protected $fillable = [
        'name', 'id_number', 'email', 'password',
        'course', 'year_level', 'section', 'department',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['role' => self::STUDENT];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'year_level' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::TEACHER;
    }

    public function isStudent(): bool
    {
        return $this->role === self::STUDENT;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst((string) $this->role);
    }

    /**
     * FR-ADM-10: true while the student still has old free-text values that the conversion
     * command couldn't turn into a course code, year level and section letter.
     */
    public function needsInfoUpdate(): bool
    {
        return filled($this->year)
            || (filled($this->course) && ! array_key_exists($this->course, StudentInfo::courses()))
            || (filled($this->section) && ! in_array($this->section, StudentInfo::sections(), true));
    }

    /** The old typed values, e.g. "Course: BS IT · Year: 4TH · Section: Section 4-B". */
    public function legacyInfo(): string
    {
        return collect(['Course' => $this->course, 'Year' => $this->year, 'Section' => $this->section])
            ->filter(fn ($v) => filled($v))->map(fn ($v, $k) => $k.': '.$v)->implode(' · ');
    }

    /** "BSIT · 4th Year · Section B", shown under the name. */
    public function academicLabel(): ?string
    {
        return $this->needsInfoUpdate() ? null : StudentInfo::full($this->course, $this->year_level, $this->section);
    }

    /** "BSIT 4-B" for badges and the report. Not-yet-converted students show their old section text. */
    public function sectionCode(): ?string
    {
        if ($this->needsInfoUpdate()) {
            return filled($this->section) ? trim($this->section) : null;
        }

        return StudentInfo::compact($this->course, $this->year_level, $this->section);
    }

    /** FR-ADM-07: deactivated accounts can't log in (and are logged out of open sessions). */
    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** Classes this user created (and therefore teaches). */
    public function taughtClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    /** Classes this user is an approved student in. */
    public function approvedClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'enrollments', 'student_id', 'class_id')
            ->wherePivot('status', Enrollment::APPROVED)
            ->withTimestamps();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * FR-ACL-03: roles are enforced. Only a Teacher can teach (a class they created), so a
     * teacher who is later made a Student or Admin loses the teaching pages until the role is given back.
     */
    public function teaches(SchoolClass $class): bool
    {
        return $this->isTeacher() && (int) $class->teacher_id === (int) $this->id;
    }

    /** Only a Student can attend, scan or ask for corrections. */
    public function isApprovedIn(SchoolClass $class): bool
    {
        return $this->isStudent() && $this->enrollments()
            ->where('class_id', $class->id)
            ->where('status', Enrollment::APPROVED)
            ->exists();
    }

    /** Words that join a compound surname, e.g. "Dela Cruz", "De los Santos", "San Juan". */
    private const SURNAME_PARTICLES = ['de', 'del', 'dela', 'la', 'las', 'los', 'delos', 'di', 'da', 'san', 'santa', 'sta.', 'sto.', 'van', 'von', 'y'];

    /** Name suffixes kept at the end, e.g. "Jr.", "III". */
    private const NAME_SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];

    /**
     * Splits the single full-name field into surname, given names (with middle initial) and suffix.
     * "Maria Teresa A. Loreno" → [Loreno, Maria Teresa A., null];
     * "Juan Miguel Dela Cruz Jr." → [Dela Cruz, Juan Miguel, Jr.].
     *
     * @return array{surname: string, given: string, suffix: ?string}
     */
    public function nameParts(): array
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $suffix = null;

        if (count($words) > 2 && in_array(strtolower(end($words)), self::NAME_SUFFIXES, true)) {
            $suffix = array_pop($words);
        }
        if (count($words) < 2) {
            return ['surname' => (string) ($words[0] ?? ''), 'given' => '', 'suffix' => $suffix];
        }

        // The surname is the last word plus any particles right before it ("Dela", "De los"),
        // as long as at least one given name is left.
        $start = count($words) - 1;
        while ($start > 1 && in_array(strtolower($words[$start - 1]), self::SURNAME_PARTICLES, true)) {
            $start--;
        }

        return [
            'surname' => implode(' ', array_slice($words, $start)),
            'given' => implode(' ', array_slice($words, 0, $start)),
            'suffix' => $suffix,
        ];
    }

    /** "Loreno, Maria Teresa A." / "Dela Cruz, Juan Miguel Jr." for class lists and reports. */
    public function listName(): string
    {
        ['surname' => $surname, 'given' => $given, 'suffix' => $suffix] = $this->nameParts();

        return trim($surname.($given !== '' ? ', '.$given : '').($suffix ? ' '.$suffix : ''));
    }

    /** Sort key: surname, then given names (case-insensitive). */
    public function surnameSortKey(): string
    {
        ['surname' => $surname, 'given' => $given] = $this->nameParts();

        return mb_strtolower($surname.' '.$given);
    }

    /** FR-PDF-03: the surname, used in file names. */
    public function lastName(): string
    {
        return $this->nameParts()['surname'];
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
