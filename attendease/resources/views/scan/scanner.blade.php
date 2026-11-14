<x-layouts.app title="Scan QR">
    @push('head')
        @vite('resources/js/scanner.js')
    @endpush

    <div class="mx-auto max-w-md">
        <h1 class="h1 mb-1">Scan attendance QR</h1>
        <p class="mb-4 text-sm text-slate-500">Point your camera at the QR code on your teacher's screen. It opens automatically when it's read.</p>

        <div id="qr-scanner" data-scan-url="{{ route('scan') }}" class="card overflow-hidden p-0 sm:p-0">
            <div class="relative aspect-square w-full bg-slate-900">
                <video class="absolute inset-0 h-full w-full object-cover" playsinline muted autoplay></video>

                {{-- Aiming frame --}}
                <div id="scanner-frame" class="pointer-events-none absolute inset-[12%] rounded-2xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(15,23,42,0.45)] transition-colors"></div>

                <div id="scanner-error" class="absolute inset-0 hidden" role="alert">
                    <div class="flex h-full items-center justify-center p-6 text-center text-sm text-white">
                        <p id="scanner-error-text"></p>
                    </div>
                </div>
            </div>

            <div class="space-y-3 p-4">
                <p id="scanner-status" class="min-h-5 text-center text-sm text-slate-600" aria-live="polite">Starting camera…</p>
                <button type="button" id="scanner-start" class="btn-primary hidden w-full">Start camera</button>
            </div>
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">
            You can also scan with your phone's normal camera app. Both work the same way.
        </p>
    </div>
</x-layouts.app>
