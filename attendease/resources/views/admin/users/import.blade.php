@use('App\Services\UserImportService', 'Import')
@use('App\Support\StudentInfo')
@php
    $courses = implode(', ', array_keys(StudentInfo::courses()));
    $sections = implode(', ', StudentInfo::sections());
    // What each column accepts, shown next to its name.
    $notes = [
        'id_number' => 'must be new; also used to log in',
        'name' => 'given names first, surname last',
        'email' => 'optional; a new '.'@'.config('app.allowed_email_domain').' address. A blank or non-school email is left out and listed in an alert after the import; the account then logs in with its ID number',
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

            @if (! empty($result['fixed']))
                <p class="mt-3 text-sm text-slate-600">Set for every student: <strong>{{ StudentInfo::full($result['fixed']['course'] ?? null, $result['fixed']['year_level'] ?? null, $result['fixed']['section'] ?? null) }}</strong>.</p>
            @endif

            {{-- FR-ADM-17: imported, but without a school email --}}
            @if ($alerts = $result['email_alerts'] ?? [])
                <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="alert" data-email-alert>
                    @php($who = count($alerts).' imported '.\Illuminate\Support\Str::plural(mb_strtolower($label), count($alerts)).(count($alerts) === 1 ? ' has' : ' have'))
                    <p class="font-semibold">{{ $who }} no {{ '@'.config('app.allowed_email_domain') }} email.</p>
                    <p class="mb-2">They can log in with their ID number. Add their school email in User Management → Edit when you have it.</p>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Row</th><th>ID number</th><th>Name</th><th>Email</th></tr></thead>
                            <tbody>
                                @foreach ($alerts as $a)
                                    <tr>
                                        <td class="font-mono">{{ $a['row'] }}</td>
                                        <td class="font-mono text-xs whitespace-nowrap">{{ $a['id_number'] }}</td>
                                        <td class="whitespace-nowrap">{{ $a['name'] }}</td>
                                        <td>{{ $a['problem'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
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

            {{-- FR-ADM-16: course / year level / section for every student row, or from the file --}}
            <fieldset data-student-only @class(['rounded-lg border border-slate-200 p-4', 'hidden' => $type !== 'student'])>
                <legend class="px-1 text-sm font-semibold text-slate-700">Students' course, year level and section</legend>
                <p class="mb-3 text-xs text-slate-500">Pick a value to give it to every student in the file (the file then doesn't need that column), or leave “From the file” to read it per row.</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-field name="course" label="Course" type="select" data-fixed="course">
                        <option value="">From the file</option>
                        @foreach (StudentInfo::courses() as $code => $name)
                            <option value="{{ $code }}" title="{{ $name }}" @selected(old('course') === $code)>{{ $code }}</option>
                        @endforeach
                    </x-field>
                    <x-field name="year_level" label="Year level" type="select" data-fixed="year_level">
                        <option value="">From the file</option>
                        @foreach (StudentInfo::yearLevels() as $level)
                            <option value="{{ $level }}" @selected((string) old('year_level') === (string) $level)>{{ StudentInfo::yearLabel($level) }}</option>
                        @endforeach
                    </x-field>
                    <x-field name="section" label="Section" type="select" data-fixed="section">
                        <option value="">From the file</option>
                        @foreach (StudentInfo::sections() as $letter)
                            <option value="{{ $letter }}" @selected(old('section') === $letter)>Section {{ $letter }}</option>
                        @endforeach
                    </x-field>
                </div>
            </fieldset>

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
                            <li><strong>{{ $heading }}</strong>@if ($required)<span class="text-red-600" data-required-mark="{{ $field }}">*</span>@endif — {{ $notes[$field] }}
                                @if ($key === 'student' && in_array($field, ['course', 'year_level', 'section'], true))
                                    <span class="hidden text-xs font-semibold text-emerald-700" data-fixed-note="{{ $field }}">(set above; not needed in the file)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            <p class="help mt-3">Headings are matched loosely (case, spaces, dots and underscores don't matter; e.g. “ID No.” or “Student ID” work for ID Number). Rows with a problem, or whose ID number or email already exists (or repeats in the file), are skipped and listed with the reason. The rest are imported.</p>
        </section>
    </div>

    @push('scripts')
    <script>
        // Show the template, columns and student-only dropdowns for the chosen type only.
        document.querySelector('[data-import-type]')?.addEventListener('change', (e) => {
            document.querySelectorAll('[data-template-for]').forEach((el) => {
                el.classList.toggle('hidden', el.dataset.templateFor !== e.target.value);
            });
            document.querySelector('[data-student-only]')?.classList.toggle('hidden', e.target.value !== 'student');
        });

        // A course / year level / section chosen above isn't needed in the file: say so next to the column.
        const markFixed = (select) => {
            const field = select.dataset.fixed;
            const fixed = select.value !== '';
            document.querySelector(`[data-fixed-note="${field}"]`)?.classList.toggle('hidden', !fixed);
            document.querySelector(`[data-template-for="student"] [data-required-mark="${field}"]`)?.classList.toggle('hidden', fixed);
        };
        document.querySelectorAll('[data-fixed]').forEach((select) => {
            markFixed(select);
            select.addEventListener('change', () => markFixed(select));
        });
    </script>
    @endpush
</x-layouts.admin>
