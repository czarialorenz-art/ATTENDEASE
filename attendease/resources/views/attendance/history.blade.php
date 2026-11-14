<x-layouts.app title="My attendance">
    <h1 class="h1 mb-4">My attendance</h1>

    <form method="GET" class="card mb-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <div>
            <label class="label" for="class_id">Class</label>
            <select id="class_id" name="class_id" class="input">
                <option value="">All classes</option>
                @foreach ($classes as $c)
                    <option value="{{ $c->id }}" @selected((string) request('class_id') === (string) $c->id)>{{ $c->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="term">School term</label>
            <select id="term" name="term" class="input">
                <option value="">All terms</option>
                @foreach ($terms as $t)
                    <option value="{{ $t }}" @selected(request('term') === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn-primary">Filter</button>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Class</th><th>Status</th><th>Time In</th><th>Time Out</th><th>Correction</th></tr></thead>
                <tbody>
                    @forelse ($records as $r)
                        @php
                            $c = $r->latestCorrection;
                            $bag = 'correction'.$r->id;
                            $hasErrors = $errors->getBag($bag)->any();
                            $options = \App\Models\AttendanceCorrection::ALLOWED[$r->status] ?? [];
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">{{ $r->attendanceSession->date->format('D, M j, Y') }}</td>
                            <td>{{ $r->attendanceSession->schoolClass->label() }}@if ($r->attendanceSession->title)<div class="text-xs text-slate-500">{{ $r->attendanceSession->title }}</div>@endif</td>
                            <td><span class="badge-{{ $r->status }}">{{ ucfirst($r->status) }}</span></td>
                            <td class="whitespace-nowrap">{{ $r->time_in_at?->format('g:i A') ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ $r->time_out_at?->format('g:i A') ?? '—' }}</td>

                            {{-- FR-ATT-18: correction request --}}
                            <td class="min-w-44">
                                @if ($c)
                                    <div class="mb-1 text-xs">
                                        <span @class(['badge-pending' => $c->status === 'pending', 'badge-approved' => $c->status === 'approved', 'badge-rejected' => $c->status === 'rejected'])>{{ ucfirst($c->status) }}</span>
                                        <span class="text-slate-500">asked for {{ ucfirst($c->requested_status) }}</span>
                                        @if ($c->teacher_note)<div class="mt-0.5 text-slate-500">Teacher: “{{ $c->teacher_note }}”</div>@endif
                                    </div>
                                @endif

                                @if ($r->canRequestCorrection())
                                    <details @if ($hasErrors) open @endif>
                                        <summary class="btn-secondary btn-sm cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                            {{ $c && $c->status === 'rejected' ? 'Request again' : 'Request correction' }}
                                        </summary>
                                        <form method="POST" action="{{ route('corrections.store', $r) }}" class="mt-2 w-64 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            @csrf
                                            <div>
                                                <label for="rs{{ $r->id }}" class="label text-xs">Should be</label>
                                                <select id="rs{{ $r->id }}" name="requested_status" class="input py-1 text-sm">
                                                    @foreach ($options as $opt)
                                                        <option value="{{ $opt }}" @selected(old('requested_status') === $opt)>{{ ucfirst($opt) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label for="rr{{ $r->id }}" class="label text-xs">What happened?</label>
                                                <textarea id="rr{{ $r->id }}" name="reason" rows="3" maxlength="1000" required class="input text-sm"
                                                    placeholder="e.g. I was in class but my phone couldn't scan the QR code.">{{ $hasErrors ? old('reason') : '' }}</textarea>
                                            </div>
                                            @foreach ($errors->getBag($bag)->all() as $message)<p class="error">{{ $message }}</p>@endforeach
                                            <button class="btn-primary btn-sm w-full">Send request</button>
                                        </form>
                                    </details>
                                @elseif (! $c)
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-slate-500">No attendance records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
