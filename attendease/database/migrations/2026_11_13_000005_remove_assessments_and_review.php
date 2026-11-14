<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Assessments, submissions, exam papers, answer-sheet reading and the class review
 * (with its Google Sheets link) were removed from the system. This drops their tables,
 * column and uploaded photos. It can't be undone: the data is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assessments');

        if (Schema::hasColumn('classes', 'sheet_token')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->dropUnique(['sheet_token']);
                $table->dropColumn('sheet_token');
            });
        }

        // Students' submission photos and the marked-up copies.
        Storage::disk('local')->deleteDirectory('submissions');
    }

    public function down(): void
    {
        // Not reversible: the features and their data were removed.
    }
};
