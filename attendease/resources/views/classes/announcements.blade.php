<x-layouts.app :title="$class->subject.' announcements'">
    <x-class-nav :class="$class" active="announcements" />

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('announcements.store', $class) }}" class="card h-fit space-y-4" novalidate>
            @csrf
            <h2 class="h2">Post to the class</h2>
            @include('classes._announcement-form')
            <p class="text-xs text-slate-500">Every enrolled student gets it in their notifications and by email.</p>
            <button class="btn-primary w-full">Post</button>
        </form>

        <div class="card lg:col-span-2" id="announcements">
            <h2 class="h2 mb-3">Announcements</h2>
            <div class="space-y-3">
                @forelse ($announcements as $a)
                    @include('classes._announcement-item', ['a' => $a, 'manage' => true])
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">Nothing posted yet. Announcements and cancellations you post appear here and on your students' dashboards.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $announcements->links() }}</div>
        </div>
    </div>
</x-layouts.app>
