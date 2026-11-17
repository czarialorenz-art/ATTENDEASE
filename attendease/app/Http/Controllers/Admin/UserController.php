<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserAccountService;
use App\Support\StudentInfo;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** FR-ADM-06..08: list, add, edit and (de)activate accounts. Accounts are never deleted here. */
class UserController extends Controller
{
    public const PER_PAGE = 20;

    public const STATUSES = ['active' => 'Active', 'deactivated' => 'Deactivated'];

    /** Fields shown in "User edited" details, in this order. */
    private const LOGGED_FIELDS = [
        'name' => 'Name', 'id_number' => 'ID number', 'email' => 'Email',
        'course' => 'Course', 'year_level' => 'Year level', 'section' => 'Section', 'department' => 'Department',
    ];

    public function __construct(private UserAccountService $accounts) {}

    /** Value of ?section= for students without a section (only on a year tab). */
    public const NO_SECTION = '__none';

    /**
     * FR-ADM-11: the tabs above the table, tab key => label, role and year level.
     *
     * @return array<string, array{label: string, role: ?string, year: ?int}>
     */
    public static function tabs(): array
    {
        $tabs = ['all' => ['label' => 'All', 'role' => null, 'year' => null]];
        foreach (StudentInfo::yearLevels() as $year) {
            $tabs['year'.$year] = ['label' => StudentInfo::yearLabel($year), 'role' => User::STUDENT, 'year' => $year];
        }
        $tabs['teachers'] = ['label' => 'Teachers', 'role' => User::TEACHER, 'year' => null];

        return $tabs;
    }

    public function index(Request $request)
    {
        $tabs = self::tabs();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(array_keys(User::ROLES))],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'tab' => ['nullable', Rule::in(array_keys($tabs))],
            'section' => ['nullable', Rule::in([...StudentInfo::sections(), self::NO_SECTION])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $tab = $filters['tab'] ?? 'all';
        $year = $tabs[$tab]['year'];
        $section = $year ? ($filters['section'] ?? '') : ''; // the Section filter belongs to a year tab

        // Search, role and status: shared by the table and the tab counts.
        $filtered = fn () => User::query()
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(id_number) like ?', [$like]));
            })
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->whereNull('deactivated_at'))
            ->when(($filters['status'] ?? null) === 'deactivated', fn ($q) => $q->whereNotNull('deactivated_at'));

        // Every tab's count in one query, using the same search, role and status filters.
        $countQuery = $filtered()->toBase()->selectRaw('count(*) as tab_all');
        foreach ($tabs as $key => $t) {
            if ($key !== 'all') {
                $countQuery->selectRaw(
                    'sum(case when role = ?'.($t['year'] ? ' and year_level = ?' : '').' then 1 else 0 end) as tab_'.$key,
                    $t['year'] ? [$t['role'], $t['year']] : [$t['role']],
                );
            }
        }
        $row = $countQuery->first();
        $counts = collect($tabs)->map(fn ($t, $key) => (int) ($row->{'tab_'.$key} ?? 0));

        $users = $filtered()
            ->when($tabs[$tab]['role'], fn ($q, $role) => $q->where('role', $role))
            ->when($year, fn ($q) => $q->where('year_level', $year))
            ->when($section === self::NO_SECTION, fn ($q) => $q->whereNull('section'))
            ->when($section !== '' && $section !== self::NO_SECTION, fn ($q) => $q->where('section', $section))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString(); // keeps the tab, section, search and filters on every page link

        return view('admin.users.index', [
            'users' => $users,
            'tabs' => $tabs,
            'tab' => $tab,
            'counts' => $counts,
            'year' => $year,
            'section' => $section,
            'search' => $search,
            'role' => $filters['role'] ?? '',
            'status' => $filters['status'] ?? '',
            'filtering' => $search !== '' || filled($filters['role'] ?? null) || filled($filters['status'] ?? null) || $section !== '',
        ]);
    }

    public function create()
    {
        return view('admin.users.form', [
            'user' => new User(['role' => User::STUDENT]),
            'hasDefaultPassword' => $this->accounts->defaultPassword() !== null,
        ]);
    }

    public function store(Request $request)
    {
        $request->merge($this->accounts->normalize($request->all()));
        $default = $this->accounts->defaultPassword();

        $data = $request->validate([
            ...$this->accounts->rules(),
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => [$default ? 'nullable' : 'required', 'string', Password::defaults()],
        ], [], UserAccountService::ATTRIBUTE_NAMES);

        $user = DB::transaction(function () use ($data, $default) {
            $user = new User(collect($data)->except(['role', 'password'])->all());
            $user->password = $data['password'] ?? $default;
            $user->role = $data['role'];
            $this->keepStudentInfoForStudentsOnly($user);
            $user->save();

            Audit::log(Audit::USER_ADDED, 'Added '.$user->roleLabel().' '.Audit::who($user).' · '.($user->email ?? 'no email')
                .(isset($data['password']) ? '' : ' (default password)'), $user, ['role' => $user->role]);

            return $user;
        });

        return redirect()->route('admin.users.index')->with('status', $user->name.' was added as '.$user->roleLabel().'.');
    }

    public function edit(User $user)
    {
        return view('admin.users.form', [
            'user' => $user,
            'hasDefaultPassword' => $this->accounts->defaultPassword() !== null,
            'classCount' => $user->taughtClasses()->count(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->merge($this->accounts->normalize($request->all()));
        $self = $request->user()->is($user);
        if ($self) {
            // Your own role can't change, so "required for students" is checked against your real role.
            $request->merge(['role' => $user->role]);
        }

        $data = $request->validate([
            ...$this->accounts->rules($user),
            // An admin can't change their own role (it would lock them out of this page).
            'role' => $self ? ['nullable'] : ['required', Rule::in(array_keys(User::ROLES))],
            'password' => ['nullable', 'string', Password::defaults()],
        ], [], UserAccountService::ATTRIBUTE_NAMES);

        $oldRole = $user->role;
        $old = $user->only(array_keys(self::LOGGED_FIELDS));

        DB::transaction(function () use ($user, $data, $self, $oldRole, $old) {
            $user->fill(collect($data)->only(array_keys(self::LOGGED_FIELDS))->all());
            if (! $self) {
                $user->role = $data['role'];
            }
            $this->keepStudentInfoForStudentsOnly($user);
            if (filled($data['password'] ?? null)) {
                $user->password = $data['password'];
            }
            $passwordChanged = $user->isDirty('password');
            $user->save();

            $changes = collect(self::LOGGED_FIELDS)
                ->filter(fn ($label, $field) => (string) ($old[$field] ?? '') !== (string) ($user->{$field} ?? ''))
                ->map(fn ($label, $field) => $label.': '.($old[$field] ?? '—').' → '.($user->{$field} ?? '—'))
                ->values();
            if ($passwordChanged) {
                $changes->push('password reset');
            }

            if ($changes->isNotEmpty()) {
                Audit::log(Audit::USER_EDITED, Audit::who($user).': '.$changes->implode('; '), $user, [
                    'old' => collect($old)->only(array_keys($user->getChanges()))->all(),
                    'new' => collect($user->getChanges())->only(array_keys(self::LOGGED_FIELDS))->all(),
                    'password_reset' => $passwordChanged,
                ]);
            }

            if ($oldRole !== $user->role) {
                Audit::log(Audit::ROLE_CHANGED, Audit::who($user).': '.(User::ROLES[$oldRole] ?? $oldRole).' → '.$user->roleLabel(), $user,
                    ['old' => $oldRole, 'new' => $user->role]);
            }
        });

        // A changed password or role ends the user's open sessions, so the change applies right away.
        if (! $self && ($oldRole !== $user->role || filled($data['password'] ?? null))) {
            $this->accounts->endSessions($user);
        }

        return redirect()->route('admin.users.index')->with('status', $user->name.' was updated.');
    }

    /**
     * FR-ADM-10: course, year level and section belong to students. Saving from the form also
     * clears the old free-text year, so a hand-fixed student counts as converted.
     */
    private function keepStudentInfoForStudentsOnly(User $user): void
    {
        $user->year = null;

        if (! $user->isStudent()) {
            $user->course = $user->year_level = $user->section = null;
        }
    }

    public function deactivate(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['admin' => 'You cannot deactivate your own account.']);
        }

        if ($user->isActive()) {
            $user->forceFill(['deactivated_at' => now()])->save();
            $this->accounts->endSessions($user);
            Audit::log(Audit::USER_DEACTIVATED, 'Deactivated '.$user->roleLabel().' '.Audit::who($user), $user);
        }

        return back()->with('status', $user->name.' was deactivated and can no longer log in.');
    }

    public function activate(User $user)
    {
        if (! $user->isActive()) {
            $user->forceFill(['deactivated_at' => null])->save();
            Audit::log(Audit::USER_ACTIVATED, 'Activated '.$user->roleLabel().' '.Audit::who($user), $user);
        }

        return back()->with('status', $user->name.' was activated and can log in again.');
    }
}
