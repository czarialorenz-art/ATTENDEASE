{{-- One announcement, as students and the teacher see it. Set $manage = true for edit/delete links. --}}
@php
    $manage = $manage ?? false;
    $upcoming = $a->isUpcomingCancellation();
@endphp
<article @class([
    'rounded-xl border p-4',
    'border-red-200 bg-red-50/60' => $a->isCancellation() && $upcoming,
    'border-slate-200 bg-white' => ! ($a->isCancellation() && $upcoming),
])>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            @if ($a->isCancellation())
                <span @class(['badge-absent' => $upcoming, 'badge-neutral' => ! $upcoming])>Class cancelled</span>
                <div class="mt-1 font-semibold text-slate-900">
                    No class on {{ $a->cancelled_date->format('l, F j, Y') }}
                    @if ($a->cancelled_date->isToday()) <span class="text-red-700">(today)</span>
                    @elseif ($a->cancelled_date->isTomorrow()) <span class="text-red-700">(tomorrow)</span>@endif
                </div>
                @if ($a->title !== 'Class cancelled')<div class="text-sm font-medium text-slate-700">{{ $a->title }}</div>@endif
            @else
                <div class="font-semibold text-slate-900">{{ $a->title }}</div>
            @endif
        </div>
        @if ($manage)
            <div class="flex shrink-0 gap-2">
                <a href="{{ route('announcements.edit', $a) }}" class="btn-secondary btn-sm">Edit</a>
                <form method="POST" action="{{ route('announcements.destroy', $a) }}" data-confirm="Delete this announcement? Students won't see it any more.">
                    @csrf @method('DELETE')<button class="btn-secondary btn-sm text-red-600">Delete</button>
                </form>
            </div>
        @endif
    </div>
    @if ($a->body)
        <p class="mt-2 text-sm whitespace-pre-line text-slate-700">{{ $a->body }}</p>
    @endif
    <p class="mt-2 text-xs text-slate-500">
        {{ $a->author?->name ?? 'Teacher' }} · posted {{ $a->created_at->format('M j, Y g:i A') }}
        @if ($a->updated_at->gt($a->created_at->copy()->addMinute())) · edited @endif
    </p>
</article>
