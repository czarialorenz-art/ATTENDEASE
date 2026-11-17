<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Live QR · {{ $session->schoolClass->subject }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-brand-800 font-sans text-white antialiased">
    <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-4 py-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-sm text-brand-100">{{ config('app.school_name') }}</div>
                <div class="text-xl font-bold sm:text-2xl">{{ $session->schoolClass->label() }} · {{ $session->date->format('M j, Y') }}</div>
            </div>
            <div class="flex gap-2">
                <button type="button" id="fs" class="btn border border-white/30 text-white hover:bg-white/10">Full screen</button>
                <a href="{{ route('sessions.show', $session) }}" class="btn border border-white/30 text-white hover:bg-white/10">Back to session</a>
            </div>
        </div>

        <div class="flex flex-1 flex-col items-center justify-center gap-6 py-6 lg:flex-row lg:gap-12">
            <div class="relative rounded-3xl bg-white p-4 shadow-2xl">
                <img id="qr" src="{{ $state['qr'] }}" alt="Attendance QR code" class="block aspect-square w-[min(80vw,70vh)] max-w-[560px] [image-rendering:pixelated] {{ $state['qr'] ? '' : 'hidden' }}">
                <div id="closed" class="grid aspect-square w-[min(80vw,70vh)] max-w-[560px] place-items-center text-center text-2xl font-bold text-slate-700 {{ $state['qr'] ? 'hidden' : '' }}">
                    No stage is open
                </div>
            </div>

            <div class="text-center lg:text-left">
                <div id="stage" class="inline-block rounded-full bg-gold-400 px-4 py-1 text-lg font-bold text-brand-800">{{ $state['stage_label'] ?? 'Closed' }}</div>
                <div class="mt-4 text-7xl font-black tabular-nums sm:text-8xl"><span id="count">{{ $state['count'] }}</span><span class="text-3xl font-semibold text-brand-100">/<span id="total">{{ $state['total'] }}</span></span></div>
                <div class="text-lg text-brand-100">students scanned</div>
                <div class="mt-4 text-sm text-brand-100">New code in <span id="rotate" class="font-semibold text-white tabular-nums">{{ $state['rotates_in'] }}</span>s</div>
                <p class="mt-6 max-w-xs text-sm text-brand-100">Scan with your phone's camera. The code changes every 30 seconds and only works today, while this stage is open.</p>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const url = @json(route('sessions.live.state', $session));
            const el = id => document.getElementById(id);
            let rotatesIn = {{ (int) $state['rotates_in'] }};
            let busy = false;

            async function refresh() {
                if (busy) return;
                busy = true;
                try {
                    const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                    if (!res.ok) return;
                    const s = await res.json();
                    if (s.qr) {
                        if (el('qr').getAttribute('src') !== s.qr) el('qr').src = s.qr;
                        el('qr').classList.remove('hidden');
                        el('closed').classList.add('hidden');
                    } else {
                        el('qr').classList.add('hidden');
                        el('closed').classList.remove('hidden');
                    }
                    el('stage').textContent = s.stage_label || 'Closed';
                    el('count').textContent = s.count;
                    el('total').textContent = s.total;
                    rotatesIn = s.rotates_in;
                } catch (e) { /* network blip: try again on the next tick */ }
                finally { busy = false; }
            }

            // Poll every 5 s (NFR performance) and also right after each 30 s rotation.
            setInterval(refresh, 5000);
            setInterval(() => {
                rotatesIn = Math.max(0, rotatesIn - 1);
                el('rotate').textContent = rotatesIn;
                if (rotatesIn === 0) { rotatesIn = 30; setTimeout(refresh, 300); }
            }, 1000);

            el('fs').addEventListener('click', () => {
                document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.();
            });
        })();
    </script>
</body>
</html>
