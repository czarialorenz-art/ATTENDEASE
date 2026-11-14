<x-layouts.app :title="$class->subject.' settings'">
    <x-class-nav :class="$class" active="schedule" />

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('classes.update', $class) }}" class="card space-y-4" novalidate>
            @csrf @method('PUT')
            <h2 class="h2">Class details</h2>
            @include('classes._form')
            <div class="flex justify-end"><button class="btn-primary">Save details</button></div>
        </form>

        <form method="POST" action="{{ route('classes.schedule', $class) }}" class="card space-y-4" novalidate>
            @csrf @method('PUT')
            <h2 class="h2">Weekly schedule</h2>

            @php
                $rows = old('schedules', $class->schedules->map(fn ($s) => [
                    'day_of_week' => $s->day_of_week, 'start_time' => substr($s->start_time, 0, 5),
                    'end_time' => substr($s->end_time, 0, 5), 'room' => $s->room,
                ])->all());
                $rows[] = ['day_of_week' => '', 'start_time' => '', 'end_time' => '', 'room' => ''];
            @endphp

            <div id="schedule-rows" class="space-y-3">
                @foreach ($rows as $i => $row)
                    <div class="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-[1.2fr_1fr_1fr_1fr]" data-row>
                        <select name="schedules[{{ $i }}][day_of_week]" class="input col-span-2 sm:col-span-1" aria-label="Day">
                            <option value="">Day…</option>
                            @foreach (\App\Models\ClassSchedule::DAYS as $d => $name)
                                <option value="{{ $d }}" @selected((string) ($row['day_of_week'] ?? '') === (string) $d)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <input type="time" name="schedules[{{ $i }}][start_time]" value="{{ $row['start_time'] ?? '' }}" class="input" aria-label="Start time">
                        <input type="time" name="schedules[{{ $i }}][end_time]" value="{{ $row['end_time'] ?? '' }}" class="input" aria-label="End time">
                        <input name="schedules[{{ $i }}][room]" value="{{ $row['room'] ?? '' }}" class="input col-span-2 sm:col-span-1" placeholder="Room" aria-label="Room">
                        @foreach (['day_of_week', 'start_time', 'end_time', 'room'] as $f)
                            @error("schedules.$i.$f")<p class="error col-span-full">{{ $message }}</p>@enderror
                        @endforeach
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn-secondary btn-sm" id="add-row">+ Add a row</button>
            <p class="help">Leave a row blank to remove it.</p>

            <h2 class="h2 pt-2">Classroom location</h2>
            <p class="text-sm text-slate-500">Used to flag scans from too far away. Leave blank to turn the check off.</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <x-field name="latitude" label="Latitude" :value="$class->latitude" inputmode="decimal" placeholder="14.6507" />
                <x-field name="longitude" label="Longitude" :value="$class->longitude" inputmode="decimal" placeholder="121.0494" />
                <x-field name="radius_m" label="Radius (m)" type="number" :value="$class->radius_m ?? 100" min="10" max="5000" />
            </div>
            <button type="button" class="btn-secondary btn-sm" id="use-location">Use my current location</button>
            <p id="loc-msg" class="help"></p>

            <div class="flex justify-end"><button class="btn-primary">Save schedule & location</button></div>
        </form>
    </div>

    @push('scripts')
    <script>
        document.getElementById('add-row').addEventListener('click', () => {
            const wrap = document.getElementById('schedule-rows');
            const last = wrap.querySelector('[data-row]:last-child');
            const clone = last.cloneNode(true);
            const n = wrap.querySelectorAll('[data-row]').length;
            clone.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/schedules\[\d+]/, `schedules[${n}]`); el.value = ''; });
            clone.querySelectorAll('.error').forEach(el => el.remove());
            wrap.appendChild(clone);
        });
        document.getElementById('use-location').addEventListener('click', () => {
            const msg = document.getElementById('loc-msg');
            if (!navigator.geolocation) { msg.textContent = 'This browser has no location support.'; return; }
            msg.textContent = 'Getting your location…';
            navigator.geolocation.getCurrentPosition(p => {
                document.getElementById('f_latitude').value = p.coords.latitude.toFixed(7);
                document.getElementById('f_longitude').value = p.coords.longitude.toFixed(7);
                msg.textContent = `Accuracy about ${Math.round(p.coords.accuracy)} m. Save to keep it.`;
            }, e => { msg.textContent = 'Could not get location: ' + e.message; }, { enableHighAccuracy: true, timeout: 10000 });
        });
    </script>
    @endpush
</x-layouts.app>
