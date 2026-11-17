@use('App\Support\Audit')
@use('App\Models\User')
@php
    $actionBadge = [
        Audit::ATTENDANCE_EDITED => 'badge-late',
        Audit::USER_ADDED => 'badge-present',
        Audit::USERS_IMPORTED => 'badge-present',
        Audit::USER_EDITED => 'badge-neutral',
        Audit::ROLE_CHANGED => 'badge-flag',
        Audit::USER_DEACTIVATED => 'badge-absent',
        Audit::USER_ACTIVATED => 'badge-approved',
    ];
@endphp
<x-layouts.admin title="Activity Log">
    <div class="mb-5">
        <h1 class="h1">Activity Log</h1>
        <p class="text-sm text-slate-500">Who changed what and when. Entries can't be edited or deleted.</p>
    </div>

    {{-- FR-LOG-03: filters --}}
    <form method="GET" action="{{ route('admin.activity.index') }}" class="card mb-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        <div>
            <label for="from" class="label">From</label>
            <input type="date" id="from" name="from" value="{{ $from }}" @class(['input', 'input-error' => $errors->has('from')])>
        </div>
        <div>
            <label for="to" class="label">To</label>
            <input type="date" id="to" name="to" value="{{ $to }}" @class(['input', 'input-error' => $errors->has('to')])>
        </div>
        <div class="sm:w-56">
            <label for="action" class="label">Action</label>
            <select id="action" name="action" class="input">
                <option value="">All actions</option>
                @foreach (Audit::ACTIONS as $key => $label)
                    <option value="{{ $key }}" @selected($action === $key)>{{ $label }}</option>
                @endforeach
            </select>
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
                        @php($role = $e->getProperty('causer_role'))
                        <tr data-event="{{ $e->event }}">
                            <td class="whitespace-nowrap">
                                <div>{{ $e->created_at->format('M j, Y') }}</div>
                                <div class="text-xs text-slate-500">{{ $e->created_at->format('g:i:s A') }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($e->causer)
                                    <div class="font-medium">{{ $e->causer->name }}</div>
                                    <div class="text-xs text-slate-500">{{ User::ROLES[$role] ?? ($role ? ucfirst($role) : $e->causer->roleLabel()) }}</div>
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
