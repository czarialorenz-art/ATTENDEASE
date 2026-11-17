@props(['title' => null])
@php
    $links = [
        ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'label' => 'Dashboard',
         'icon' => 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10'],
        ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'label' => 'User Management',
         'icon' => 'M16 19v-1a4 4 0 00-4-4H6a4 4 0 00-4 4v1M9 10a3 3 0 100-6 3 3 0 000 6M22 19v-1a4 4 0 00-3-3.9M16 4.1a3 3 0 010 5.8'],
        ['route' => 'admin.activity.index', 'match' => 'admin.activity.*', 'label' => 'Activity Log',
         'icon' => 'M12 8v4l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Admin · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
    <header class="sticky top-0 z-30 border-b border-brand-800 bg-brand-600 text-white">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5">
            <a href="{{ route('admin.dashboard') }}" class="flex min-w-0 items-center gap-2">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-gold-400 text-sm font-black text-brand-800">MQ</span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-bold">{{ config('app.name') }} <span class="ml-1 rounded bg-white/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase">Admin</span></span>
                    <span class="hidden truncate text-[11px] text-brand-100 sm:block">{{ config('app.school_name') }}</span>
                </span>
            </a>

            <nav class="ml-auto flex items-center gap-1">
                <details class="relative">
                    <summary class="flex cursor-pointer list-none items-center gap-1 rounded-lg px-2 py-1.5 hover:bg-white/10">
                        <span class="grid h-7 w-7 place-items-center rounded-full bg-white/20 text-xs font-bold">{{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"/></svg>
                    </summary>
                    <div class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm text-slate-700 shadow-lg">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <div class="truncate font-semibold text-slate-900">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-slate-500">{{ auth()->user()->email }} · Admin</div>
                        </div>
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

    <div class="mx-auto max-w-7xl px-4 py-5 sm:py-8 md:flex md:gap-8">
        {{-- FR-ADM-01: admin sidebar (a scrolling tab row on phones) --}}
        <aside class="mb-5 md:mb-0 md:w-56 md:shrink-0">
            <nav class="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 md:sticky md:top-20 md:mx-0 md:flex-col md:px-0" aria-label="Admin">
                @foreach ($links as $link)
                    @php($active = request()->routeIs($link['match']))
                    <a href="{{ route($link['route']) }}" @if ($active) aria-current="page" @endif
                       @class(['flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap',
                               'bg-brand-50 text-brand-700' => $active,
                               'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $active])>
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $link['icon'] }}"/></svg>
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>
        </aside>

        <main class="min-w-0 flex-1">
            @if (session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->has('admin'))
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $errors->first('admin') }}</div>
            @endif

            {{ $slot }}
        </main>
    </div>

    @stack('scripts')
</body>
</html>
