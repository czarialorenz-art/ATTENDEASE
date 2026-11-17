@use('App\Exports\UserImportTemplate')
<x-layouts.admin title="Import users">
    <div class="mb-5">
        <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-brand-600 hover:underline">← User Management</a>
        <h1 class="h1 mt-1">Import students and teachers</h1>
    </div>

    {{-- FR-ADM-09: summary of the last import --}}
    @if ($result)
        @php($skipped = $result['skipped'])
        <section class="card mb-6" data-import-summary>
            <h2 class="h2">{{ $result['imported'] }} imported, {{ count($skipped) }} skipped</h2>
            <p class="text-sm text-slate-500">
                From {{ $result['file'] }} · {{ $result['students'] }} {{ \Illuminate\Support\Str::plural('student', $result['students']) }},
                {{ $result['teachers'] }} {{ \Illuminate\Support\Str::plural('teacher', $result['teachers']) }}.
                @if ($result['imported']) New accounts log in with their email and the default password. @endif
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
        <form method="POST" action="{{ route('admin.users.import.store') }}" enctype="multipart/form-data" class="card space-y-4 lg:col-span-3">
            @csrf
            <h2 class="h2">Upload a file</h2>
            @unless ($hasDefaultPassword)
                <p class="rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-900">
                    <strong>Set IMPORT_DEFAULT_PASSWORD in .env first.</strong> Imported accounts get that password; it must have 8+ characters with upper and lower case letters, a number and a symbol.
                </p>
            @endunless
            <x-field name="file" label="CSV or Excel file" type="file" accept=".csv,.xlsx,.xls" required help="Up to 5 MB and 2,000 rows. Only the first sheet is read." />
            <button class="btn-primary" @disabled(! $hasDefaultPassword)>Import</button>
        </form>

        <section class="card lg:col-span-2">
            <h2 class="h2 mb-2">Template</h2>
            <div class="mb-3 flex flex-wrap gap-2">
                <a href="{{ route('admin.users.import.template', 'xlsx') }}" class="btn-secondary btn-sm">Download Excel template</a>
                <a href="{{ route('admin.users.import.template', 'csv') }}" class="btn-secondary btn-sm">Download CSV template</a>
            </div>
            <p class="mb-2 text-sm text-slate-600">Keep the heading row and add one person per row:</p>
            <ul class="space-y-1 text-sm text-slate-600">
                <li><strong>Role</strong>* Student or Teacher</li>
                <li><strong>ID Number</strong>* must be new</li>
                <li><strong>Name</strong>* given names first, surname last</li>
                <li><strong>Email</strong>* school email ({{ '@'.config('app.allowed_email_domain') }}), must be new</li>
                <li><strong>Course, Year, Section</strong> for students; <strong>Department</strong> for teachers (optional)</li>
            </ul>
            <p class="help mt-3">Rows with a problem, or whose ID number or email already exists (or repeats in the file), are skipped and listed with the reason. The rest are imported.</p>
            <p class="sr-only">Columns: {{ implode(', ', UserImportTemplate::HEADINGS) }}</p>
        </section>
    </div>
</x-layouts.admin>
