<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * FR-MAIL-02: time (Asia/Manila), IP and browser for security alerts,
 * captured when the event happens (not when the queued email is sent).
 */
final class RequestContext
{
    /** @return array{time: string, ip: string, browser: string} */
    public static function capture(?Request $request = null): array
    {
        $request ??= request();

        return [
            'time' => now()->timezone('Asia/Manila')->format('M j, Y g:i A').' (Asia/Manila)',
            'ip' => (string) ($request?->ip() ?? 'unknown'),
            'browser' => self::browser((string) $request?->userAgent()),
        ];
    }

    public static function browser(string $ua): string
    {
        if ($ua === '') {
            return 'Unknown browser';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} on {$os}" : $browser;
    }
}
