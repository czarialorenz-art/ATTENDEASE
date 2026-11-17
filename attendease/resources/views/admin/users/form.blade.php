@use('App\Models\User')
@php
    $editing = $user->exists;
    $self = $editing && auth()->user()->is($user);
    $classCount ??= 0;
@endphp
<x-layouts.admin :title="$editing ? 'Edit user' : 'Add user'">
    <div class="mb-5">
        <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-brand-600 hover:underline">← User Management</a>
        <h1 class="h1 mt-1">{{ $editing ? 'Edit '.$user->name : 'Add user' }}</h1>
        @if ($editing && ! $user->isActive())
            <p class="mt-1 text-sm text-red-700">This account is deactivated (since {{ $user->deactivated_at->format('M j, Y') }}).</p>
        @endif
    </div>

    <form method="POST" action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}" class="card max-w-3xl space-y-5" novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" label="Full name" :value="$user->name" required maxlength="255" class="sm:col-span-2"
                help="Given names first, surname last, e.g. Maria Teresa A. Loreno." />
            <x-field name="id_number" label="ID number" :value="$user->id_number" required maxlength="50" />
            <x-field name="email" label="School email" type="email" :value="$user->email" required maxlength="255"
                :help="'Must end in @'.config('app.allowed_email_domain').'.'" />

            {{-- FR-ADM-08: role --}}
            <div>
                <label for="f_role" class="label">Role <span class="text-red-600">*</span></label>
                <select id="f_role" name="role" @class(['input', 'input-error' => $errors->has('role')]) @disabled($self) required>
                    @foreach (User::ROLES as $key => $label)
                        <option value="{{ $key }}" @selected(old('role', $user->role) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @if ($self)
                    <p class="help">You can't change your own role.</p>
                @elseif ($classCount > 0)
                    <p class="help text-amber-700">Teaches {{ $classCount }} {{ \Illuminate\Support\Str::plural('class', $classCount) }}. If the role is no longer Teacher, they lose access to {{ $classCount === 1 ? 'it' : 'them' }} until it is changed back.</p>
                @endif
                @error('role')<p class="error">{{ $message }}</p>@enderror
            </div>
            <x-field name="section" label="Section" :value="$user->section" maxlength="50" help="Students only, e.g. BSIT-3A." />
            <x-field name="course" label="Course" :value="$user->course" maxlength="255" />
            <x-field name="year" label="Year level" :value="$user->year" maxlength="50" />
            <x-field name="department" label="Department" :value="$user->department" maxlength="255" class="sm:col-span-2" help="Teachers only." />
        </div>

        <div class="border-t border-slate-100 pt-5">
            <x-field name="password" :label="$editing ? 'New password' : 'Password'" type="password" autocomplete="new-password" :rules="true"
                :required="! $editing && ! $hasDefaultPassword"
                :help="$editing
                    ? 'Leave blank to keep the current password. Setting one logs the user out everywhere.'
                    : ($hasDefaultPassword ? 'Leave blank to use the default password (IMPORT_DEFAULT_PASSWORD).' : 'No default password is set (IMPORT_DEFAULT_PASSWORD), so enter one.')" />
        </div>

        <div class="flex gap-2">
            <button class="btn-primary">{{ $editing ? 'Save changes' : 'Add user' }}</button>
            <a href="{{ route('admin.users.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
