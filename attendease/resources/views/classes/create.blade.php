<x-layouts.app title="Create a class">
    <div class="mx-auto max-w-2xl">
        <h1 class="h1 mb-1">Create a class</h1>
        <p class="mb-5 text-sm text-slate-500">You'll be this class's teacher. A join code is generated for you to share.</p>

        {{-- FR-CLS-17: class details and the classroom location in one form --}}
        <form method="POST" action="{{ route('classes.store') }}" class="card space-y-6" novalidate>
            @csrf
            <section class="space-y-4">
                <h2 class="h2">Class details</h2>
                @include('classes._form')
            </section>
            <section class="space-y-4 border-t border-slate-100 pt-5">
                <div>
                    <h2 class="h2">Classroom location</h2>
                    <p class="text-sm text-slate-500">Optional. Shown to your students on their class page.</p>
                </div>
                @include('classes._location-fields', ['bag' => 'default'])
            </section>
            <p class="text-sm text-slate-500">You can add the weekly schedule on the class's Schedule tab after creating it.</p>
            <div class="flex justify-end gap-2">
                <a href="{{ route('dashboard') }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary">Create class</button>
            </div>
        </form>
    </div>
</x-layouts.app>
