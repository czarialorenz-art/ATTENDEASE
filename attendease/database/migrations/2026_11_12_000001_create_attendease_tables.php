<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MTQ-AttendEase data model (PRD section 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->index();
            $table->text('user_agent')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject');
            $table->string('year_level');
            $table->string('section');
            $table->string('school_term', 20);
            $table->string('semester', 10); // 1st, 2nd, Summer
            $table->date('semester_start');
            $table->date('semester_end');
            $table->string('code', 8)->unique();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->default(100);
            $table->timestamps();
        });

        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday ... 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room')->nullable();
            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('pending'); // pending / approved / rejected / removed
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['class_id', 'student_id']);
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->date('date');
            $table->string('title')->nullable();
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->timestamp('time_in_opened_at')->nullable();
            $table->timestamp('time_in_closed_at')->nullable();
            $table->timestamp('time_out_opened_at')->nullable();
            $table->timestamp('time_out_closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10); // present / late / absent
            $table->timestamp('time_in_at')->nullable();
            $table->timestamp('time_out_at')->nullable();
            $table->string('source', 10)->default('qr'); // qr / manual
            $table->timestamps();
            $table->unique(['attendance_session_id', 'student_id']);
        });

        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 10); // time_in / time_out
            $table->string('ip', 45)->nullable();
            $table->string('device_id', 64)->nullable()->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->decimal('distance_m', 12, 2)->nullable();
            $table->timestamp('scanned_at')->index();
            $table->timestamps();
        });

        Schema::create('scan_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_log_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20); // NO_LOCATION / TOO_FAR / SHARED_DEVICE / IP_BURST
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
            $table->unique(['scan_log_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_flags');
        Schema::dropIfExists('scan_logs');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('class_schedules');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('user_devices');
    }
};
