<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/**
 * FR-LOG-03/04: everyone's activity (admins, teachers and students), newest first, filtered by
 * date range, action type, the person's role at the time and a name / ID number search. Read-only.
 */
class ActivityLogController extends Controller
{
    public const PER_PAGE = 25;

    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'action' => ['nullable', Rule::in(array_keys(Audit::actions()))],
            'role' => ['nullable', Rule::in(array_keys(User::ROLES))],
            'q' => ['nullable', 'string', 'max:100'],
        ], ['to.after_or_equal' => 'The end date must be on or after the start date.']);
        $search = trim((string) ($filters['q'] ?? ''));

        $entries = Activity::query()
            ->with('causer')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('event', $action))
            // The role the person had when they did it (stored with each entry).
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('properties->causer_role', $role))
            // Who did it (name or ID number) — or who it was about, since the details name them too.
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $q->where(fn ($w) => $w
                    ->whereHasMorph('causer', [User::class], fn ($u) => $u->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(id_number) like ?', [$like]))
                    ->orWhereRaw('lower(description) like ?', [$like]));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.activity.index', [
            'entries' => $entries,
            'from' => $filters['from'] ?? '',
            'to' => $filters['to'] ?? '',
            'action' => $filters['action'] ?? '',
            'role' => $filters['role'] ?? '',
            'search' => $search,
            'filtering' => collect($filters)->filter()->isNotEmpty(),
        ]);
    }
}
