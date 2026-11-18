@use('App\Support\Audit')
@use('App\Models\User')
@php
    // Badge colour per action: green = added/approved, red = removed/deactivated/deleted,
    // amber = attendance changes, orange = role changes, grey = everything else.
    $actionBadge = [
        Audit::ATTENDANCE_SCANNED => 'badge-approved',
        Audit::ATTENDANCE_EDITED => 'badge-late',
        Audit::CORRECTION_REQUESTED => 'badge-pending',
        Audit::CORRECTION_APPROVED => 'badge-approved',
        Audit::CORRECTION_REJECTED => 'badge-rejected',
        Audit::SESSION_CREATED => 'badge-present',
        Audit::SESSION_ARCHIVED => 'badge-late',
        Audit::SESSION_RESTORED => 'badge-approved',
        Audit::SESSION_DELETED => 'badge-absent',
        Audit::CLASS_CREATED => 'badge-present',
        Audit::SCHEDULE_ADDED => 'badge-present',
        Audit::SCHEDULE_REMOVED => 'badge-absent',
        Audit::JOIN_REQUESTED => 'badge-pending',
        Audit::JOIN_APPROVED => 'badge-approved',
        Audit::JOIN_REJECTED => 'badge-rejected',
        Audit::STUDENT_REMOVED => 'badge-absent',
        Audit::ANNOUNCEMENT_POSTED => 'badge-present',
        Audit::ANNOUNCEMENT_DELETED => 'badge-absent',
        Audit::USER_ADDED => 'badge-present',
        Audit::USERS_IMPORTED => 'badge-present',
        Audit::ROLE_CHANGED => 'badge-flag',
        Audit::USER_DEACTIVATED => 'badge-absent',
        Audit::USER_ACTIVATED => 'badge-approved',
    ];
    $roleBadge = ['admin' => 'badge bg-brand-50 text-brand-700', 'teacher' => 'badge bg-sky-100 text-sky-800', 'student' => 'badge-neutral'];
@endphp
<x-layouts.admin title="Activity Log">
    <div class="mb-5">
        <h1 class="h1">Activity Log</h1>
        <p class="text-sm text-slate-500">Who did what and when: admins, teachers and students. Entries can't be edited or deleted.</p>
    </div>

    {{-- FR-LOG-03: filters --}}
    <form method="GET" action="{{ route('admin.activity.index') }}" class="card mb-5 flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-end" role="search">
        <div class="min-w-0 lg:flex-1">
            <label for="q" class="label">Search</label>
            <input type="search" id="q" name="q" value="{{ $search }}" maxlength="100" placeholder="Name or ID number" class="input">
        </div>
        <div class="lg:w-40">
            <label for="role" class="label">Role</label>
            <select id="role" name="role" class="input">
                <option value="">All roles</option>
                @foreach (User::ROLES as $key => $label)
                    <option value="{{ $key }}" @selected($role === $key)>{{ $label }}s</option>
                @endforeach
            </select>
        </div>
        <div class="lg:w-60">
            <label for="action" class="label">Action</label>
            <select id="action" name="action" class="input">
                <option value="">All actions</option>
                @foreach (Audit::GROUPS as $group => $actions)
                    <optgroup label="{{ $group }}">
                        @foreach ($actions as $key => $label)
                            <option value="{{ $key }}" @selected($action === $key)>{{ $label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="from" class="label">From</label>
            <input type="date" id="from" name="from" value="{{ $from }}" @class(['input', 'input-error' => $errors->has('from')])>
        </div>
        <div>
            <label for="to" class="label">To</label>
            <input type="date" id="to" name="to" value="{{ $to }}" @class(['input', 'input-error' => $errors->has('to')])>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary">Filter</button>
            @if ($filtering)
                <a href="{{ route('admin.activity.index') }}" class="btn-secondary">Clear</a>
            @endif
        </div>
        @if ($errors->any())
            <p class="error w-full">{{ $errors->first() }}</p>
        @endif
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date &amp; Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
                <tbody>
                    @forelse ($entries as $e)
                        @php($causerRole = $e->getProperty('causer_role') ?? $e->causer?->role)
                        <tr data-event="{{ $e->event }}">
                            <td class="whitespace-nowrap">
                                <div>{{ $e->created_at->format('M j, Y') }}</div>
                                <div class="text-xs text-slate-500">{{ $e->created_at->format('g:i:s A') }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($e->causer)
                                    <div class="font-medium">{{ $e->causer->name }}</div>
                                    <span class="{{ $roleBadge[$causerRole] ?? 'badge-neutral' }}">{{ User::ROLES[$causerRole] ?? ucfirst((string) $causerRole) }}</span>
                                @else
                                    <div class="text-slate-500">System</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap"><span class="{{ $actionBadge[$e->event] ?? 'badge-neutral' }}">{{ Audit::actionLabel($e->event) }}</span></td>
                            <td class="min-w-72">{{ $e->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-500">{{ $filtering ? 'No activity matches these filters.' : 'No activity yet.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $entries->links() }}</div>
    </div>
</x-layouts.admin>
