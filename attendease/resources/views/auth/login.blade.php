<x-layouts.guest title="Log in">
    <h1 class="mb-6 text-xl font-bold">Log in</h1>

    <form method="POST" action="{{ route('login') }}" class="space-y-4" novalidate>
        @csrf
        <x-field name="login" label="ID Number or School Email" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus
            help="Accounts without an email log in with their ID number." />
        <x-field name="password" label="Password" type="password" autocomplete="current-password" required />

        <div class="flex items-center justify-between text-sm">
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300" @checked(old('remember'))>
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="font-medium text-brand-600 hover:underline">Forgot password?</a>
        </div>

        <button class="btn-primary w-full">Log in</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-600">
        New here? <a href="{{ route('register') }}" class="font-semibold text-brand-600 hover:underline">Create an account</a>
    </p>
</x-layouts.guest>
