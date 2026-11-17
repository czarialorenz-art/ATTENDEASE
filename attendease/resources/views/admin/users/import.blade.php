@use('App\Services\UserImportService', 'Import')
@use('App\Support\StudentInfo')
@php
    $courses = implode(', ', array_keys(StudentInfo::courses()));
    $sections = implode(', ', StudentInfo::sections());
    // What each column accepts, shown next to its name.
    $notes = [
        'id_number' => 'must be new; also used to log in',
        'name' => 'given names first, surname last',
        'email' => 'optional; a new '.'@'.config('app.allowed_email_domain').' address. Without one, the account logs in with its ID number',
        'course' => $courses,
        'year_level' => '1 to 4 (also "1st", "4th Year")',
        'section' => $sections,
        'department' => 'optional',
    ];
@endphp
<x-layouts.admin title="Import users">
    <div class="mb-5">
        <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-brand-600 hover:underline">← User Management</a>
        <h1 class="h1 mt-1">Import students and teachers</h1>
    </div>

    {{-- FR-ADM-09: summary of the last import --}}
    @if ($result)
        @php($skipped = $result['skipped'])
        @php($label = Import::TYPES[$result['type']])
        <section class="card mb-6" data-import-summary>
            <h2 class="h2">{{ $result['imported'] }} imported, {{ count($skipped) }} skipped</h2>
            <p class="text-sm text-slate-500">
                {{ $label }} import from {{ $result['file'] }}.
                @if ($result['imported']) New accounts log in with their ID number (or email) and the default password. @endif
            </p>
            @if ($skipped)
                <div class="table-wrap mt-4">
                    <table class="table">
                        <thead><tr><th>Row</th><th>ID number</th><th>Why it was skipped</th></tr></thead>
                        <tbody>
                            @foreach ($skipped as $s)
                                <tr>
                                    <td class="font-mono">{{ $s['row'] }}</td>
                                    <td class="font-mono text-xs whitespace-nowrap">{{ $s['id_number'] ?? '—' }}</td>
                                    <td><ul class="list-disc pl-4 text-red-700">@foreach ($s['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="help mt-2">Fix these rows and import the file again: rows already imported are skipped as duplicates.</p>
            @endif
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        <form method="POST" action="{{ route('admin.users.import.store') }}" enctype="multipart/form-data" class="card space-y-4 lg:col-span-3" novalidate>
            @csrf
            <h2 class="h2">Upload a file</h2>
            @unless ($hasDefaultPassword)
                <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">
                    <strong>Set IMPORT_DEFAULT_PASSWORD in .env first.</strong> Imported accounts get that password; it must have 8+ characters with upper and lower case letters, a number and a symbol.
                </p>
            @endunless

            {{-- FR-ADM-14: every row gets this role --}}
            <x-field name="type" label="Import as" type="select" required data-import-type
                help="Every row in the file gets this role. Import students and teachers in separate files.">
                <option value="" @selected($type === '')>Choose…</option>
                @foreach (Import::TYPES as $key => $label)
                    <option value="{{ $key }}" @selected($type === $key)>{{ $label }}s</option>
                @endforeach
            </x-field>

            <x-field name="file" label="CSV or Excel file" type="file" accept=".csv,.xlsx,.xls" required help="Up to 5 MB and 2,000 rows. Only the first sheet is read." />
            <button class="btn-primary" @disabled(! $hasDefaultPassword)>Import</button>
        </form>

        <section class="card lg:col-span-2" aria-live="polite">
            <h2 class="h2 mb-2">Template</h2>
            <p data-template-for="" @class(['text-sm text-slate-500', 'hidden' => $type !== ''])>Choose <strong>Students</strong> or <strong>Teachers</strong> under “Import as” to see the template and its columns.</p>

            @foreach (Import::TYPES as $key => $label)
                <div data-template-for="{{ $key }}" @class(['hidden' => $type !== $key])>
                    <div class="mb-3 flex flex-wrap gap-2">
                        <a href="{{ route('admin.users.import.template', [$key, 'xlsx']) }}" class="btn-secondary btn-sm">Download {{ $label }} Excel template</a>
                        <a href="{{ route('admin.users.import.template', [$key, 'csv']) }}" class="btn-secondary btn-sm">Download {{ $label }} CSV template</a>
                    </div>
                    <p class="mb-2 text-sm text-slate-600">Keep the heading row and add one {{ mb_strtolower($label) }} per row (* = required):</p>
                    <ul class="space-y-1 text-sm text-slate-600">
                        @foreach (Import::COLUMNS[$key] as $field => [$heading, $required])
                            <li><strong>{{ $heading }}</strong>@if ($required)<span class="text-red-600">*</span>@endif — {{ $notes[$field] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <p class="help mt-3">Headings are matched loosely (case, spaces, dots and underscores don't matter; e.g. “ID No.” or “Student ID” work for ID Number). Rows with a problem, or whose ID number or email already exists (or repeats in the file), are skipped and listed with the reason. The rest are imported.</p>
        </section>
    </div>

    @push('scripts')
    <script>
        // Show the template and columns for the chosen type only.
        document.querySelector('[data-import-type]')?.addEventListener('change', (e) => {
            document.querySelectorAll('[data-template-for]').forEach((el) => {
                el.classList.toggle('hidden', el.dataset.templateFor !== e.target.value);
            });
        });
    </script>
    @endpush
</x-layouts.admin>
