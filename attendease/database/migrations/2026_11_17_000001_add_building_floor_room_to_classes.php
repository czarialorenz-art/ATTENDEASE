<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-CLS-03: the classroom is described by building, floor and room name
 * (instead of map coordinates and a radius, which were removed from the form).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('building', 100)->nullable()->after('radius_m');
            $table->string('floor', 50)->nullable()->after('building');
            $table->string('room', 100)->nullable()->after('floor');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['building', 'floor', 'room']);
        });
    }
};
