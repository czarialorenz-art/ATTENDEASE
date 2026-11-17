<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-ADM-13: email becomes optional (imported accounts may have none). The unique index stays:
 * several NULLs never clash, so blank emails are never duplicates. Such users log in with
 * their ID number (FR-AUTH-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        // Any empty-string emails become NULL so they can't collide with each other.
        DB::table('users')->where('email', '')->update(['email' => null]);
    }

    public function down(): void
    {
        // Accounts without an email can't go back to a required column; give them a placeholder.
        DB::table('users')->whereNull('email')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['email' => 'no-email-'.$user->id.'@invalid.local']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
