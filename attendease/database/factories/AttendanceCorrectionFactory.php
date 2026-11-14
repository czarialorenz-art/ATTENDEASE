<?php

namespace Database\Factories;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceCorrectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory()->state(['status' => AttendanceRecord::ABSENT]),
            'student_id' => fn (array $a) => AttendanceRecord::find($a['attendance_record_id'])->student_id,
            'current_status' => AttendanceRecord::ABSENT,
            'requested_status' => AttendanceRecord::PRESENT,
            'reason' => 'I was in class; my phone could not scan the QR code.',
            'status' => AttendanceCorrection::PENDING,
        ];
    }
}
