<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/** FR-LOG-03/04: newest first, filter by date range and action type. Read-only. */
class ActivityLogController extends Controller
{
    public const PER_PAGE = 25;

    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'action' => ['nullable', Rule::in(array_keys(Audit::ACTIONS))],
        ], ['to.after_or_equal' => 'The end date must be on or after the start date.']);

        $entries = Activity::query()
            ->with('causer')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('event', $action))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.activity.index', [
            'entries' => $entries,
            'from' => $filters['from'] ?? '',
            'to' => $filters['to'] ?? '',
            'action' => $filters['action'] ?? '',
            'filtering' => collect($filters)->filter()->isNotEmpty(),
        ]);
    }
}
