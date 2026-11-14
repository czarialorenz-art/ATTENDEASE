<?php

namespace Database\Factories;

use App\Models\ScanFlag;
use App\Models\ScanLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScanFlagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scan_log_id' => ScanLog::factory(),
            'code' => ScanFlag::NO_LOCATION,
        ];
    }
}