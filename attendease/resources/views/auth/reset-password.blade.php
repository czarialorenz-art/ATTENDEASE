<x-layouts.guest title="Reset password">
    <h1 class="mb-6 text-xl font-bold">Choose a new password</h1>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="School Email" type="email" :value="$email" autocomplete="email" required />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" :rules="true" required />
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />
        <button class="btn-primary w-full">Reset password</button>
    </form>
</x-layouts.guest>
