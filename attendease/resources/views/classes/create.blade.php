<x-layouts.app title="Create a class">
    <div class="mx-auto max-w-2xl">
        <h1 class="h1 mb-1">Create a class</h1>
        <p class="mb-5 text-sm text-slate-500">You'll be this class's teacher. A join code is generated for you to share.</p>

        <form method="POST" action="{{ route('classes.store') }}" class="card space-y-4" novalidate>
            @csrf
            @include('classes._form')
            <div class="flex justify-end gap-2">
                <a href="{{ route('dashboard') }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary">Create class</button>
            </div>
        </form>
    </div>
</x-layouts.app>
