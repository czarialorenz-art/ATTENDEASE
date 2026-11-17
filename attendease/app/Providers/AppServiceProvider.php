<?php

namespace App\Providers;

use App\Models\SchoolClass;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // {class} in routes binds to the "classes" table ("Class" is a reserved word in PHP).
        Route::model('class', SchoolClass::class);

        // FR-AUTH-04: one password rule for sign-up, reset and change password.
        // Max 64: bcrypt only uses the first 72 bytes, so longer passwords would be silently cut.
        Password::defaults(fn () => Password::min(8)->max(64)->letters()->mixedCase()->numbers()->symbols());

        // Share the unread count with the top bar (FR-NOTIF-01).
        View::composer('components.layouts.app', function ($view) {
            $view->with('unreadCount', auth()->user()?->unreadNotifications()->count() ?? 0);
            // FR-ACL-03: Scan QR and My attendance are for Students only.
            $view->with('showScanner', auth()->user()?->isStudent() ?? false);
        });
    }
}
