<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attendance_session_id' => AttendanceSession::factory(),
            'student_id' => User::factory(),
            'status' => AttendanceRecord::PRESENT,
            'time_in_at' => now(),
            'time_out_at' => null,
            'source' => AttendanceRecord::SOURCE_QR,
        ];
    }
}