<x-layouts.app :title="'Edit announcement'">
    <x-class-nav :class="$class" active="announcements" />

    <div class="mx-auto max-w-xl">
        <form method="POST" action="{{ route('announcements.update', $announcement) }}" class="card space-y-4" novalidate>
            @csrf @method('PUT')
            <h2 class="h2">Edit announcement</h2>
            @include('classes._announcement-form', ['announcement' => $announcement])
            <p class="text-xs text-slate-500">Saving an edit doesn't notify students again.</p>
            <div class="flex justify-end gap-2">
                <a href="{{ route('announcements.index', $class) }}" class="btn-secondary">Cancel</a>
                <button class="btn-primary">Save changes</button>
            </div>
        </form>
    </div>
</x-layouts.app>
