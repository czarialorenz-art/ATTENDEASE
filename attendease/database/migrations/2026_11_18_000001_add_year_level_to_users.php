<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-ADM-10: a student's year becomes a number (1-4) in year_level, the course a code
 * from config/students.php and the section a letter.
 *
 * The old free-text `year` column is kept for now: `php artisan attendease:convert-student-info`
 * reads it, fills year_level, normalizes course and section, and empties `year` for every
 * student it converts. Students it can't convert keep their old text until fixed by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('year_level')->nullable()->after('course');
            $table->index(['role', 'year_level', 'section']); // User Management year tabs and section filter
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'year_level', 'section']);
            $table->dropColumn('year_level');
        });
    }
};
