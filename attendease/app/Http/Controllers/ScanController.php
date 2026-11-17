<?php

namespace App\Http\Controllers;

use App\Models\AttendanceSession;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use Illuminate\Http\Request;

/** FR-ATT-05..10: the page a phone opens after scanning the QR. */
class ScanController extends Controller
{
    public const EXPIRED = 'This QR code has expired.';

    public function __construct(private QrTokenService $tokens, private AttendanceService $attendance) {}

    public function show(Request $request)
    {
        $token = (string) $request->query('t');
        $verified = $this->tokens->verify($token);

        if (! $verified) {
            return view('scan.result', ['ok' => false, 'title' => self::EXPIRED, 'message' => 'Ask your teacher to show the current code and scan again.']);
        }

        [$session, $stage] = $verified;
        $this->authorize('attend', $session->schoolClass);

        return view('scan.show', ['token' => $token, 'session' => $session, 'stage' => $stage]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            't' => ['required', 'string', 'max:500'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ]);

        $verified = $this->tokens->verify($data['t']);

        if (! $verified) {
            return $this->respond($request, false, self::EXPIRED, 'Ask your teacher to show the current code and scan again.', 410);
        }

        [$session, $stage] = $verified;
        $this->authorize('attend', $session->schoolClass);

        // The cookie is the primary device ID; the posted (localStorage) value is the fallback.
        $deviceId = $this->cleanDeviceId($request->cookie('ae_device')) ?? $this->cleanDeviceId($data['device_id'] ?? null);

        $result = $this->attendance->recordScan($session, $stage, $request->user(), [
            'device_id' => $deviceId,
            'ip' => $request->ip(),
        ]);

        $label = AttendanceSession::stageLabel($stage);

        if ($result['already']) {
            return $this->respond($request, true, 'Already recorded at '.$result['already']->format('g:i A'),
                "Your {$label} for {$session->schoolClass->label()} was already recorded. Nothing changed.");
        }

        $record = $result['record'];
        $status = $stage === AttendanceSession::TIME_IN ? ' — '.ucfirst($record->status) : '';

        return $this->respond($request, true, "{$label} recorded{$status}",
            $session->schoolClass->label().' · '.now()->format('M j, Y g:i A'));
    }

    private function cleanDeviceId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $value) ? $value : null;
    }

    private function respond(Request $request, bool $ok, string $title, string $message, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'title' => $title, 'message' => $message], $status);
        }

        return response()->view('scan.result', compact('ok', 'title', 'message'), $status);
    }
}
