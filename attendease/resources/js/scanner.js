// In-app QR scanner for attendance (/scanner).
// Reads the rotating QR from the teacher's screen with the phone camera, then opens
// the same /scan?t=... page a normal camera app would. Only this app's codes are accepted.
import jsQR from 'jsqr';

const root = document.getElementById('qr-scanner');

if (root) {
    const video = root.querySelector('video');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const statusEl = document.getElementById('scanner-status');
    const errorEl = document.getElementById('scanner-error');
    const startBtn = document.getElementById('scanner-start');
    const frame = document.getElementById('scanner-frame');

    const scanUrl = new URL(root.dataset.scanUrl, window.location.origin);
    const allowedOrigins = new Set([window.location.origin, scanUrl.origin]);

    let stream = null;
    let rafId = null;
    let lastDecode = 0;
    let done = false;
    let wrongCodeTimer = null;

    const setStatus = (text) => (statusEl.textContent = text);

    function showError(text) {
        document.getElementById('scanner-error-text').textContent = text;
        errorEl.classList.remove('hidden');
        startBtn.classList.remove('hidden');
        frame.classList.add('hidden');
        setStatus('');
    }

    function stop() {
        if (rafId) cancelAnimationFrame(rafId);
        rafId = null;
        stream?.getTracks().forEach((t) => t.stop());
        stream = null;
        video.srcObject = null;
    }

    /** Returns the /scan URL to open (on this host) if the text is one of our codes. */
    function attendanceUrl(text) {
        let url;
        try {
            url = new URL(text, window.location.origin);
        } catch {
            return null;
        }
        if (!allowedOrigins.has(url.origin)) return null;
        if (url.pathname !== scanUrl.pathname || !url.searchParams.get('t')) return null;

        // Stay on the host the student is signed in on, even if APP_URL differs slightly.
        return window.location.origin + url.pathname + url.search;
    }

    function tick(now) {
        rafId = requestAnimationFrame(tick);
        if (done || video.readyState < video.HAVE_ENOUGH_DATA || now - lastDecode < 120) return;
        lastDecode = now;

        // Decode a downscaled frame: fast on low-end phones, still plenty for a full-screen QR.
        const scale = Math.min(1, 720 / Math.max(video.videoWidth, video.videoHeight));
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const code = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
        if (!code || !code.data) return;

        const target = attendanceUrl(code.data);
        if (!target) {
            setStatus("That isn't an attendance QR code. Point at the code on your teacher's screen.");
            clearTimeout(wrongCodeTimer);
            wrongCodeTimer = setTimeout(() => setStatus('Point your camera at the QR code.'), 2500);
            return;
        }

        done = true;
        navigator.vibrate?.(80);
        frame.classList.add('border-emerald-400');
        setStatus('Code found. Recording your attendance…');
        stop();
        window.location.assign(target);
    }

    async function start() {
        errorEl.classList.add('hidden');
        startBtn.classList.add('hidden');
        frame.classList.remove('hidden');
        done = false;

        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            showError('The camera only works over a secure (https://) connection. Ask your teacher for the https link, or scan with your phone\'s camera app.');
            return;
        }

        setStatus('Starting camera…');
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
        } catch (e) {
            const messages = {
                NotAllowedError: 'Camera access was blocked. Allow the camera for this site in your browser settings, then tap "Start camera".',
                NotFoundError: 'No camera was found on this device.',
                NotReadableError: 'The camera is being used by another app. Close it, then tap "Start camera".',
            };
            showError(messages[e.name] || 'Could not start the camera. You can also scan with your phone\'s camera app.');
            return;
        }

        video.srcObject = stream;
        await video.play().catch(() => {});
        setStatus('Point your camera at the QR code.');
        rafId = requestAnimationFrame(tick);
    }

    startBtn.addEventListener('click', start);

    // Release the camera when the tab is hidden; resume when it comes back.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stop();
        } else if (!done && startBtn.classList.contains('hidden')) {
            start();
        }
    });
    window.addEventListener('pagehide', stop);

    start();
}
