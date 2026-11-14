<x-layouts.app title="Change password">
    <div class="mx-auto max-w-md">
        <h1 class="h1 mb-5">Change password</h1>

        <form method="POST" action="{{ route('password.update') }}" class="card space-y-4" novalidate>
            @csrf @method('PUT')
            <x-field name="current_password" label="Current password" type="password" autocomplete="current-password" required />
            <x-field name="password" label="New password" type="password" autocomplete="new-password" :rules="true" required />
            <x-field name="password_confirmation" label="Repeat new password" type="password" autocomplete="new-password" required />
            <p class="text-xs text-slate-500">You'll stay signed in here. Every other device will be signed out.</p>
            <button class="btn-primary w-full">Change password</button>
        </form>
    </div>
</x-layouts.app>
