{{-- FR-CLS-16: check a class list (Name, Section) against who has joined. Only a check: nothing is saved. --}}
@use('App\Services\ClassListCheckService')
@use('App\Http\Controllers\ClassListCheckController')
@php
    $check = session(ClassListCheckController::sessionKey($class));
    $groupStyle = [
        ClassListCheckService::JOINED => ['badge-approved', 'border-emerald-200'],
        ClassListCheckService::PENDING => ['badge-pending', 'border-amber-200'],
        ClassListCheckService::NOT_JOINED => ['badge-absent', 'border-red-200'],
        ClassListCheckService::POSSIBLE => ['badge-flag', 'border-orange-200'],
    ];
@endphp
<section class="card" id="class-list-check">
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h2 class="h2">Check class list</h2>
        <span class="text-sm">
            Download template:
            <a href="{{ route('class-list.template', [$class, 'csv']) }}" class="text-brand-600 hover:underline">CSV</a> ·
            <a href="{{ route('class-list.template', [$class, 'xlsx']) }}" class="text-brand-600 hover:underline">Excel</a>
        </span>
    </div>
    <p class="mb-3 text-sm text-slate-500">Upload your list with a <strong>Name</strong> and a <strong>Section</strong> column to see who has joined this class. This only checks; it doesn't add or notify anyone.</p>

    <form method="POST" action="{{ route('class-list.check', $class) }}" enctype="multipart/form-data" class="flex flex-col gap-2 sm:flex-row sm:items-start" novalidate>
        @csrf
        <div class="min-w-0 flex-1">
            <label for="class-list-file" class="sr-only">Class list file</label>
            <input id="class-list-file" type="file" name="file" accept=".csv,.xlsx,.xls" required
                @class(['input py-1.5', 'input-error' => $errors->has('file')])>
            @error('file')<p class="error">{{ $message }}</p>@enderror
            <p class="help">.csv or .xlsx, up to 2 MB.</p>
        </div>
        <button class="btn-primary shrink-0">Check list</button>
    </form>

    @if ($check)
        <div class="mt-4 border-t border-slate-100 pt-4" data-class-list-result>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600"><span class="font-medium text-slate-800">{{ $check['file'] }}</span> · {{ $check['rows'] }} {{ \Illuminate\Support\Str::plural('row', $check['rows']) }}</p>
                <div class="flex gap-2">
                    <a href="{{ route('class-list.result', $class) }}" class="btn-secondary btn-sm">Download result (CSV)</a>
                    <form method="POST" action="{{ route('class-list.clear', $class) }}">@csrf @method('DELETE')<button class="btn-secondary btn-sm">Clear</button></form>
                </div>
            </div>

            @if ($check['missing_name'])
                <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" data-missing-name>
                    {{ count($check['missing_name']) }} {{ \Illuminate\Support\Str::plural('row', count($check['missing_name'])) }} with no name {{ count($check['missing_name']) === 1 ? 'was' : 'were' }} skipped: row {{ implode(', ', $check['missing_name']) }}.
                </p>
            @endif

            <div class="space-y-3">
                @foreach (ClassListCheckService::GROUPS as $key => $label)
                    @php($items = $check['groups'][$key])
                    <details class="rounded-lg border {{ $groupStyle[$key][1] }}" @if ($items && $key !== ClassListCheckService::JOINED) open @endif data-group="{{ $key }}">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 [&::-webkit-details-marker]:hidden">
                            <span class="font-medium">{{ $label }}</span>
                            <span class="{{ $groupStyle[$key][0] }}" data-group-count>{{ count($items) }}</span>
                        </summary>
                        @if ($items)
                            <ul class="divide-y divide-slate-100 border-t border-slate-100 text-sm">
                                @foreach ($items as $r)
                                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 px-3 py-1.5">
                                        <span>
                                            <span class="font-medium">{{ $r['name'] }}</span>
                                            <span class="text-slate-500">· {{ $r['section'] !== '' ? $r['section'] : 'no section' }}</span>
                                        </span>
                                        <span class="text-xs text-slate-500">
                                            @if (in_array($key, [ClassListCheckService::JOINED, ClassListCheckService::PENDING], true))
                                                <span class="font-mono">{{ $r['id_number'] }}</span>
                                            @elseif ($key === ClassListCheckService::POSSIBLE)
                                                In class as {{ $r['student_section'] ?? 'no section' }} · <span class="font-mono">{{ $r['id_number'] }}</span>
                                            @else
                                                Row {{ $r['row'] }}
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </details>
                @endforeach
            </div>
        </div>
    @endif
</section>
