<?php

namespace App\Services;

use App\Models\AttendanceSession;

/**
 * FR-ATT-04/05: rotating QR tokens, HMAC-signed with APP_KEY.
 *
 * A token carries session_id, stage and issued_at (the start of its 30-second window).
 * The server accepts the current window's token and the one just before it.
 */
class QrTokenService
{
    public const WINDOW_SECONDS = 30;

    public function issue(AttendanceSession $session, string $stage, ?int $timestamp = null): string
    {
        $timestamp ??= now()->getTimestamp();
        $issuedAt = intdiv($timestamp, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;

        $payload = $this->b64(json_encode(['s' => $session->id, 'g' => $stage, 'i' => $issuedAt]));

        return $payload.'.'.$this->sign($payload);
    }

    /** Seconds until the current window's token is replaced. */
    public function secondsUntilRotation(): int
    {
        return self::WINDOW_SECONDS - (now()->getTimestamp() % self::WINDOW_SECONDS);
    }

    /**
     * Returns [AttendanceSession, stage] if the token is valid right now, otherwise null.
     *
     * @return array{0: AttendanceSession, 1: string}|null
     */
    public function verify(?string $token): ?array
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);

        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $data = json_decode($this->unb64($payload), true);

        if (! is_array($data) || ! isset($data['s'], $data['g'], $data['i'])
            || ! in_array($data['g'], AttendanceSession::STAGES, true)) {
            return null;
        }

        $tokenWindow = intdiv((int) $data['i'], self::WINDOW_SECONDS);
        $currentWindow = intdiv(now()->getTimestamp(), self::WINDOW_SECONDS);

        if ($tokenWindow !== $currentWindow && $tokenWindow !== $currentWindow - 1) {
            return null;
        }

        $session = AttendanceSession::with('schoolClass')->find((int) $data['s']);

        if (! $session || ! $session->isToday() || ! $session->isStageOpen($data['g'])) {
            return null;
        }

        return [$session, $data['g']];
    }

    private function sign(string $payload): string
    {
        return $this->b64(hash_hmac('sha256', $payload, (string) config('app.key'), true));
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function unb64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
