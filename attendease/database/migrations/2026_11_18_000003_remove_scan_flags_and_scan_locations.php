<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suspicious-scan flags (NO_LOCATION, TOO_FAR, SHARED_DEVICE, IP_BURST) are removed.
 * Scans no longer ask for the phone's location, and the class map coordinates only existed
 * for the TOO_FAR check, so those columns go too. Each scan's time, IP and device stay in
 * scan_logs. This deletes all flag data and can't be undone (down() only restores empty columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('scan_flags');

        Schema::table('scan_logs', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accuracy', 'distance_m']);
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'radius_m']);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->default(100);
        });

        Schema::table('scan_logs', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->decimal('distance_m', 12, 2)->nullable();
        });

        Schema::create('scan_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_log_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
            $table->unique(['scan_log_id', 'code']);
        });
    }
};
