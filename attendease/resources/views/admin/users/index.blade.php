@use('App\Models\User')
@use('App\Support\StudentInfo')
@use('App\Http\Controllers\Admin\UserController')
@php
    // Links keep the search and filters; switching tabs drops the page and the section.
    $keep = array_filter(['q' => $search, 'role' => $role, 'status' => $status]);
@endphp
<x-layouts.admin title="User Management">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="h1">User Management</h1>
            <p class="text-sm text-slate-500">{{ number_format($users->total()) }} {{ \Illuminate\Support\Str::plural('user', $users->total()) }}{{ $filtering || $tab !== 'all' ? ' match' : '' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.users.import') }}" class="btn-secondary">Import CSV / Excel</a>
            <a href="{{ route('admin.users.create') }}" class="btn-primary">+ Add user</a>
        </div>
    </div>

    {{-- FR-ADM-06: search and filters (they apply inside the selected tab) --}}
    <form method="GET" action="{{ route('admin.users.index') }}" class="card mb-5 flex flex-col gap-3 lg:flex-row lg:items-end" role="search">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div class="min-w-0 flex-1">
            <label for="user-search" class="label">Search</label>
            <input id="user-search" type="search" name="q" value="{{ $search }}" maxlength="100" autocomplete="off" placeholder="Name or ID number" class="input">
        </div>
        <div class="lg:w-40">
            <label for="user-role" class="label">Role</label>
            <select id="user-role" name="role" class="input" onchange="this.form.submit()">
                <option value="">All roles</option>
                @foreach (User::ROLES as $key => $label)
                    <option value="{{ $key }}" @selected($role === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="lg:w-40">
            <label for="user-status" class="label">Status</label>
            <select id="user-status" name="status" class="input" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (UserController::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        {{-- FR-ADM-12: Section filter, only on a year tab --}}
        @if ($year)
            <div class="lg:w-40">
                <label for="user-section" class="label">Section</label>
                <select id="user-section" name="section" class="input" onchange="this.form.submit()">
                    <option value="">All sections</option>
                    @foreach (StudentInfo::sections() as $s)
                        <option value="{{ $s }}" @selected($section === $s)>Section {{ $s }}</option>
                    @endforeach
                    <option value="{{ UserController::NO_SECTION }}" @selected($section === UserController::NO_SECTION)>No section</option>
                </select>
            </div>
        @endif
        <div class="flex gap-2">
            <button class="btn-primary">Search</button>
            @if ($filtering)
                <a href="{{ route('admin.users.index', $tab === 'all' ? [] : ['tab' => $tab]) }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </form>

    <div class="card">
        {{-- FR-ADM-11: tabs with counts --}}
        <nav class="-mx-4 mb-4 flex gap-1 overflow-x-auto border-b border-slate-100 px-4 pb-3 sm:mx-0 sm:px-0" aria-label="User groups">
            @foreach ($tabs as $key => $t)
                <a href="{{ route('admin.users.index', $key === 'all' ? $keep : [...$keep, 'tab' => $key]) }}"
                   @class(['tab-active' => $tab === $key, 'tab' => $tab !== $key]) @if ($tab === $key) aria-current="page" @endif data-tab="{{ $key }}">
                    {{ $t['label'] }}
                    <span @class(['ml-1 rounded-full px-1.5 py-0.5 text-[11px] font-semibold', 'bg-brand-100 text-brand-700' => $tab === $key, 'bg-slate-100 text-slate-500' => $tab !== $key]) data-count>{{ number_format($counts[$key]) }}</span>
                </a>
            @endforeach
        </nav>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>ID number</th><th>Email</th><th>Role</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        @php($self = auth()->user()->is($u))
                        <tr @class(['bg-slate-50/60 text-slate-500' => ! $u->isActive()])>
                            <td class="whitespace-nowrap">
                                <div class="font-medium">{{ $u->name }} @if ($self)<span class="badge-neutral">you</span>@endif</div>
                                {{-- FR-ADM-10: "BSIT · 4th Year · Section B" --}}
                                @if ($u->isStudent())
                                    @if ($u->needsInfoUpdate())
                                        <div class="text-xs text-amber-700" title="Pick the course, year level and section in Edit">Needs update — was: {{ $u->legacyInfo() }}</div>
                                    @elseif ($label = $u->academicLabel())
                                        <div class="text-xs text-slate-500" data-academic>{{ $label }}</div>
                                    @else
                                        <div class="text-xs text-slate-400">No course, year or section yet</div>
                                    @endif
                                @elseif ($u->department)
                                    <div class="text-xs text-slate-500">{{ $u->department }}</div>
                                @endif
                            </td>
                            <td class="font-mono text-xs whitespace-nowrap">{{ $u->id_number }}</td>
                            <td class="whitespace-nowrap">
                                @if ($u->email)
                                    {{ $u->email }}
                                @else
                                    <span class="badge border border-dashed border-slate-300 font-normal text-slate-400" title="Logs in with the ID number" data-no-email>No email</span>
                                @endif
                            </td>
                            <td><span class="{{ ['admin' => 'badge bg-brand-50 text-brand-700', 'teacher' => 'badge bg-sky-100 text-sky-800'][$u->role] ?? 'badge-neutral' }}">{{ $u->roleLabel() }}</span></td>
                            <td>
                                @if ($u->isActive())
                                    <span class="badge-present">Active</span>
                                @else
                                    <span class="badge-absent" title="Since {{ $u->deactivated_at->format('M j, Y g:i A') }}">Deactivated</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <div class="inline-flex gap-2">
                                    <a href="{{ route('admin.users.edit', $u) }}" class="btn-secondary btn-sm">Edit</a>
                                    @if ($u->isActive())
                                        @unless ($self)
                                            <form method="POST" action="{{ route('admin.users.deactivate', $u) }}" data-confirm="Deactivate {{ $u->name }}? They won't be able to log in until you activate the account again.">
                                                @csrf<button class="btn-secondary btn-sm text-red-600">Deactivate</button>
                                            </form>
                                        @endunless
                                    @else
                                        <form method="POST" action="{{ route('admin.users.activate', $u) }}">
                                            @csrf<button class="btn-success btn-sm">Activate</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-slate-500">{{ $filtering || $tab !== 'all' ? 'No users match.' : 'No users yet.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $users->links() }}</div>
    </div>
</x-layouts.admin>
