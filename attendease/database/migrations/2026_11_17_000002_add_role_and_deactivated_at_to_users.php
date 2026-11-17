<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin side: every account gets one role (admin / teacher / student), and accounts are
 * deactivated instead of deleted (deactivated_at set = Deactivated, null = Active).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 10)->default('student')->after('email')->index();
            $table->timestamp('deactivated_at')->nullable()->after('role');
        });

        // Existing accounts: anyone who teaches a class becomes a Teacher; everyone else stays a Student.
        DB::table('users')
            ->whereIn('id', DB::table('classes')->select('teacher_id'))
            ->update(['role' => 'teacher']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'deactivated_at']);
        });
    }
};
