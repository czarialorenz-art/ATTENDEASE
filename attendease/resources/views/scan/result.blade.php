<x-layouts.app title="Scan">
    <div class="mx-auto max-w-md">
        <div class="card text-center">
            <div @class(['mx-auto mb-3 grid h-16 w-16 place-items-center rounded-full text-3xl', 'bg-emerald-100 text-emerald-700' => $ok, 'bg-red-100 text-red-700' => ! $ok])>{{ $ok ? '✓' : '!' }}</div>
            <h1 class="text-lg font-bold">{{ $title }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $message }}</p>
            @unless ($ok)
                <a href="{{ route('scanner') }}" class="btn-primary mt-6 w-full">Scan again</a>
            @endunless
            <a href="{{ route('dashboard') }}" @class(['btn-secondary w-full', 'mt-6' => $ok, 'mt-2' => ! $ok])>Go to dashboard</a>
        </div>
    </div>
</x-layouts.app>
