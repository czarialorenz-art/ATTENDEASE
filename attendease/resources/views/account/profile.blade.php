<x-layouts.app title="Profile">
    <div class="mx-auto max-w-2xl">
        <h1 class="h1 mb-5">Profile</h1>

        <form method="POST" action="{{ route('profile.update') }}" class="card space-y-4" novalidate>
            @csrf @method('PUT')
            <x-field name="name" label="Full Name" :value="$user->name" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="email" label="School Email" type="email" :value="$user->email"
                    :help="'Optional. Must end in @'.config('app.allowed_email_domain').'. Needed for email alerts and Forgot password.'" />
                <x-field name="id_number" label="ID Number" :value="$user->id_number" required />
            </div>
            @if ($user->isStudent())
                {{-- FR-ADM-10: the same fixed lists as the admin uses --}}
                <x-student-info-fields :user="$user" />
            @else
                <x-field name="department" label="Department" :value="$user->department" placeholder="Optional" />
            @endif
            <div class="flex justify-end gap-2">
                <a href="{{ route('password.edit') }}" class="btn-secondary">Change password</a>
                <button class="btn-primary">Save profile</button>
            </div>
        </form>
    </div>
</x-layouts.app>
