<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * FR-AUTH-09: recognise browsers with a long-lived cookie token, stored hashed in user_devices.
 */
class DeviceService
{
    public const COOKIE = 'ae_dt';
    private const FIVE_YEARS_MINUTES = 60 * 24 * 365 * 5;

    /** Returns true if this browser is new for this user. */
    public function touch(User $user, Request $request): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || strlen($token) < 40) {
            $token = Str::random(64);
            Cookie::queue(Cookie::make(self::COOKIE, $token, self::FIVE_YEARS_MINUTES, httpOnly: true, sameSite: 'lax'));
        }

        $hash = hash('sha256', $token);

        $device = $user->devices()->where('token_hash', $hash)->first();

        if ($device) {
            $device->update(['last_seen_at' => now(), 'ip' => $request->ip(), 'user_agent' => $request->userAgent()]);

            return false;
        }

        UserDevice::create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'last_seen_at' => now(),
        ]);

        return true;
    }
}
