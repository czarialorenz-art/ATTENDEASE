<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_record_id', 'stage', 'ip', 'device_id',
        'latitude', 'longitude', 'accuracy', 'distance_m', 'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy' => 'float',
            'distance_m' => 'float',
            'scanned_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(ScanFlag::class);
    }
}
