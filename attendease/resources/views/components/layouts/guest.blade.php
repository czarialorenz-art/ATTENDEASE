<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-photo-dark min-h-screen bg-brand-800 font-sans text-slate-900 antialiased">
    <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-8">
        <div class="mb-6 text-center text-white">
            <div class="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-gold-400 text-xl font-black text-brand-800 shadow-lg">MQ</div>
            <div class="text-xl font-bold">{{ config('app.name') }}</div>
            <div class="text-sm text-brand-100">{{ config('app.school_name') }}</div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-xl sm:p-8">
            @if (session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
            @endif

            {{ $slot }}
        </div>
    </div>
</body>
</html>
