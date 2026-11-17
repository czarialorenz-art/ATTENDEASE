<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <header class="sticky top-0 z-30 border-b border-brand-800 bg-brand-600 text-white">
        <div class="mx-auto flex max-w-screen-2xl items-center gap-3 px-4 py-2.5 sm:px-6 lg:px-8">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gold-400 text-sm font-black text-brand-800">MQ</span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-bold">{{ config('app.name') }}</span>
                    <span class="hidden truncate text-[11px] text-brand-100 sm:block">{{ config('app.school_name') }}</span>
                </span>
            </a>

            <nav class="ml-auto flex items-center gap-1">
                @if ($showScanner)
                <a href="{{ route('scanner') }}" class="flex items-center gap-1.5 rounded-lg bg-gold-400 px-3 py-1.5 text-sm font-semibold text-brand-800 hover:bg-gold-500" aria-label="Scan attendance QR">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 011-1h2M17 4h2a1 1 0 011 1v2M20 17v2a1 1 0 01-1 1h-2M7 20H5a1 1 0 01-1-1v-2M8 8h3v3H8zM13 13h3v3h-3zM13 8h3M8 13v3"/></svg>
                    <span class="hidden sm:inline">Scan QR</span>
                </a>
                @endif
                @if (auth()->user()->isAdmin())
                <a href="{{ route('admin.dashboard') }}" class="hidden rounded-lg px-3 py-1.5 text-sm hover:bg-white/10 md:inline">Admin</a>
                @else
                <a href="{{ route('dashboard') }}" class="hidden rounded-lg px-3 py-1.5 text-sm hover:bg-white/10 md:inline">Dashboard</a>
                @endif
                @if ($showScanner)
                <a href="{{ route('attendance.history') }}" class="hidden rounded-lg px-3 py-1.5 text-sm hover:bg-white/10 md:inline">My attendance</a>
                @endif

                <a href="{{ route('notifications.index') }}" class="relative rounded-lg p-2 hover:bg-white/10" aria-label="Notifications ({{ $unreadCount }} unread)">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    @if ($unreadCount > 0)
                        <span class="absolute -top-0.5 -right-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-gold-400 px-1 text-[11px] font-bold text-brand-800">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    @endif
                </a>

                <details class="relative">
                    <summary class="flex cursor-pointer list-none items-center gap-1 rounded-lg px-2 py-1.5 hover:bg-white/10">
                        <span class="grid h-7 w-7 place-items-center rounded-full bg-white/20 text-xs font-bold">{{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"/></svg>
                    </summary>
                    <div class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm text-slate-700 shadow-lg">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <div class="truncate font-semibold text-slate-900">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</div>
                        </div>
                        <a href="{{ route(auth()->user()->isAdmin() ? 'admin.dashboard' : 'dashboard') }}" class="block px-4 py-2 hover:bg-slate-50 md:hidden">{{ auth()->user()->isAdmin() ? 'Admin' : 'Dashboard' }}</a>
                        @if ($showScanner)
                        <a href="{{ route('attendance.history') }}" class="block px-4 py-2 hover:bg-slate-50 md:hidden">My attendance</a>
                        @endif
                        <a href="{{ route('profile.edit') }}" class="block px-4 py-2 hover:bg-slate-50">Profile</a>
                        <a href="{{ route('password.edit') }}" class="block px-4 py-2 hover:bg-slate-50">Change password</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="block w-full px-4 py-2 text-left text-red-600 hover:bg-slate-50">Log out</button>
                        </form>
                    </div>
                </details>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-screen-2xl px-4 py-5 sm:px-6 sm:py-8 lg:px-8">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->hasAny(['stage', 'enrollment', 'confirm']))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                {{ $errors->first('stage') ?: ($errors->first('enrollment') ?: $errors->first('confirm')) }}
            </div>
        @endif

        {{ $slot }}
    </main>

    @stack('scripts')
</body>
</html>
