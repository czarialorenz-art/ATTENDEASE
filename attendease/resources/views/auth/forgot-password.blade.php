<x-layouts.guest title="Forgot password">
    <h1 class="mb-1 text-xl font-bold">Forgot your password?</h1>
    <p class="mb-6 text-sm text-slate-500">Enter your school email. If it has an account, we'll send a reset link that works once, for 15 minutes.</p>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4" novalidate>
        @csrf
        <x-field name="email" label="School Email" type="email" autocomplete="email" inputmode="email" required autofocus />
        <button class="btn-primary w-full">Email me a reset link</button>
    </form>

    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:underline">Back to log in</a></p>
</x-layouts.guest>
