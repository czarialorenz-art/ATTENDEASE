{{-- FR-CLS-17: "Edit class details" from the class's "…" menu: the class details and the classroom location. --}}
<x-layouts.app :title="$class->subject.' details'">
    <x-class-nav :class="$class" active="details" />

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('classes.update', $class) }}" class="card space-y-4" novalidate id="class-details">
            @csrf @method('PUT')
            <h2 class="h2">Class details</h2>
            @include('classes._form')
            <div class="flex justify-end"><button class="btn-primary">Save details</button></div>
        </form>

        {{-- FR-CLS-03: where the class meets --}}
        <form method="POST" action="{{ route('classes.location', $class) }}" class="card space-y-4" novalidate id="class-location">
            @csrf @method('PUT')
            <div>
                <h2 class="h2">Classroom location</h2>
                <p class="text-sm text-slate-500">Shown to your students on their class page. All optional.</p>
            </div>
            @include('classes._location-fields', ['bag' => 'location', 'values' => $class->only(['building', 'floor', 'room'])])
            <div class="flex justify-end"><button class="btn-primary">Save location</button></div>
        </form>
    </div>
    <p class="mt-4 text-sm text-slate-500">The weekly schedule is on the <a href="{{ route('classes.schedule', $class) }}" class="text-brand-600 hover:underline">Schedule</a> tab.</p>
</x-layouts.app>
