<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanFlag extends Model
{
    use HasFactory;

    public const NO_LOCATION = 'NO_LOCATION';
    public const TOO_FAR = 'TOO_FAR';
    public const SHARED_DEVICE = 'SHARED_DEVICE';
    public const IP_BURST = 'IP_BURST';

    public const DESCRIPTIONS = [
        self::NO_LOCATION => 'Location was denied, unavailable or timed out',
        self::TOO_FAR => 'Scanned outside the classroom radius',
        self::SHARED_DEVICE => 'Device already used by another student this session',
        self::IP_BURST => '3+ scans from one IP within 3 seconds',
    ];

    protected $fillable = ['scan_log_id', 'code', 'cleared_by', 'cleared_at'];

    protected function casts(): array
    {
        return ['cleared_at' => 'datetime'];
    }

    public function scanLog(): BelongsTo
    {
        return $this->belongsTo(ScanLog::class);
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function isCleared(): bool
    {
        return $this->cleared_at !== null;
    }
}
