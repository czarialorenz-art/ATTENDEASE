<x-layouts.guest title="Sign up">
    <h1 class="mb-1 text-xl font-bold">Create your account</h1>
    <p class="mb-6 text-sm text-slate-500">Teachers and students use the same account.</p>

    <form method="POST" action="{{ route('register') }}" class="space-y-4" novalidate>
        @csrf
        <x-field name="name" label="Full Name" autocomplete="name" required autofocus />
        <x-field name="id_number" label="ID Number" placeholder="2023-00123" required />
        <x-field name="email" label="School Email" type="email" autocomplete="email" inputmode="email"
            :placeholder="'you@'.config('app.allowed_email_domain')" required />
        <x-field name="password" label="Password" type="password" autocomplete="new-password" :rules="true" required />

        <button class="btn-primary w-full">Sign up</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account? <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:underline">Log in</a>
    </p>
</x-layouts.guest>
