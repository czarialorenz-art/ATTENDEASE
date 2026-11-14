<x-layouts.app title="Notifications">
    <div class="mx-auto max-w-2xl">
        <div class="mb-5 flex items-center justify-between gap-3">
            <h1 class="h1">Notifications</h1>
            @if (auth()->user()->unreadNotifications()->exists())
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button class="btn-secondary btn-sm">Mark all as read</button>
                </form>
            @endif
        </div>

        <div class="card divide-y divide-slate-100 p-0 sm:p-0">
            @forelse ($notifications as $n)
                <div @class(['flex gap-3 px-4 py-3', 'bg-brand-50/60' => ! $n->read_at])>
                    <span @class(['mt-1.5 h-2 w-2 shrink-0 rounded-full', 'bg-brand-600' => ! $n->read_at, 'bg-transparent' => $n->read_at])></span>
                    <div class="min-w-0 flex-1">
                        <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                            @csrf
                            <input type="hidden" name="follow" value="1">
                            <button class="text-left">
                                <div class="font-medium text-slate-900">{{ $n->data['title'] ?? 'Notification' }}</div>
                                <div class="text-sm text-slate-600">{{ $n->data['message'] ?? '' }}</div>
                            </button>
                        </form>
                        <div class="mt-1 flex items-center gap-3 text-xs text-slate-500">
                            <span>{{ $n->created_at->diffForHumans() }}</span>
                            @unless ($n->read_at)
                                <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                                    @csrf
                                    <button class="font-medium text-brand-600 hover:underline">Mark as read</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-slate-500">No notifications yet.</p>
            @endforelse
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    </div>
</x-layouts.app>
