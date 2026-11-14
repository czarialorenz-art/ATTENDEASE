<x-layouts.app title="Scan">
    <div class="mx-auto max-w-md">
        <div class="card text-center" id="scan-card">
            <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ \App\Models\AttendanceSession::stageLabel($stage) }}</div>
            <h1 class="mt-1 text-xl font-bold">{{ $session->schoolClass->label() }}</h1>
            <p class="text-sm text-slate-500">{{ $session->date->format('l, M j, Y') }}{{ $session->title ? ' · '.$session->title : '' }}</p>

            <div id="busy" class="mt-6 flex flex-col items-center gap-3">
                <div class="h-10 w-10 animate-spin rounded-full border-4 border-brand-100 border-t-brand-600"></div>
                <p id="busy-msg" class="text-sm text-slate-600">Checking your location…</p>
            </div>

            <div id="result" class="mt-6 hidden"></div>

            <noscript>
                <form method="POST" action="{{ route('scan.store') }}" class="mt-6">
                    @csrf
                    <input type="hidden" name="t" value="{{ $token }}">
                    <button class="btn-primary w-full">Record my attendance</button>
                </form>
            </noscript>
        </div>
    </div>

    @push('scripts')
    <script>
        (() => {
            const token = @json($token);
            const postUrl = @json(route('scan.store'));
            const csrf = document.querySelector('meta[name="csrf-token"]').content;

            // FR-ATT-07: a random device ID kept in a long-lived cookie and in localStorage.
            function deviceId() {
                const fromCookie = (document.cookie.match(/(?:^|; )ae_device=([^;]+)/) || [])[1];
                let id = fromCookie;
                try { id = id || localStorage.getItem('ae_device'); } catch (e) {}
                if (!id) {
                    id = (crypto.randomUUID ? crypto.randomUUID()
                        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                            const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                        }));
                }
                try { localStorage.setItem('ae_device', id); } catch (e) {}
                const secure = location.protocol === 'https:' ? '; Secure' : '';
                document.cookie = `ae_device=${id}; Max-Age=${60 * 60 * 24 * 365 * 5}; Path=/; SameSite=Lax${secure}`;
                return id;
            }

            function getLocation() {
                return new Promise(resolve => {
                    if (!navigator.geolocation) return resolve(null);
                    navigator.geolocation.getCurrentPosition(
                        p => resolve(p.coords),
                        () => resolve(null), // denied, unavailable or timed out: still record, server flags it
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
                    );
                });
            }

            function show(ok, title, message) {
                document.getElementById('busy').classList.add('hidden');
                const box = document.getElementById('result');
                box.classList.remove('hidden');
                box.innerHTML = '';
                const icon = document.createElement('div');
                icon.className = 'mx-auto mb-3 grid h-16 w-16 place-items-center rounded-full text-3xl ' + (ok ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700');
                icon.textContent = ok ? '✓' : '!';
                const h = document.createElement('div'); h.className = 'text-lg font-bold'; h.textContent = title;
                const p = document.createElement('p'); p.className = 'mt-1 text-sm text-slate-600'; p.textContent = message;
                const a = document.createElement('a'); a.href = @json(route('dashboard')); a.className = 'btn-secondary w-full ' + (ok ? 'mt-6' : 'mt-2'); a.textContent = 'Go to dashboard';
                box.append(icon, h, p);
                if (!ok) {
                    const again = document.createElement('a'); again.href = @json(route('scanner')); again.className = 'btn-primary mt-6 w-full'; again.textContent = 'Scan again';
                    box.append(again);
                }
                box.append(a);
            }

            (async () => {
                const coords = await getLocation();
                document.getElementById('busy-msg').textContent = 'Recording…';
                const body = new FormData();
                body.append('t', token);
                body.append('device_id', deviceId());
                if (coords) {
                    body.append('latitude', coords.latitude);
                    body.append('longitude', coords.longitude);
                    body.append('accuracy', coords.accuracy);
                }
                try {
                    const res = await fetch(postUrl, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
                    const data = await res.json().catch(() => ({}));
                    if (res.status === 403) return show(false, 'Not allowed', 'You are not an approved student in this class.');
                    if (res.status === 419) return show(false, 'Session expired', 'Reload the page and scan again.');
                    show(!!data.ok, data.title || 'Something went wrong', data.message || 'Please scan again.');
                } catch (e) {
                    show(false, 'No connection', 'Check your internet connection and scan again.');
                }
            })();
        })();
    </script>
    @endpush
</x-layouts.app>
