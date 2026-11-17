<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSchedule extends Model
{
    use HasFactory;

    public const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $fillable = ['class_id', 'day_of_week', 'start_time', 'end_time', 'room'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function dayName(): string
    {
        return self::DAYS[$this->day_of_week] ?? '?';
    }

    /** "8:00 AM – 9:30 AM" */
    public function timeRange(): string
    {
        $fmt = fn ($t) => \Illuminate\Support\Carbon::createFromFormat('H:i', substr((string) $t, 0, 5))->format('g:i A');

        return $fmt($this->start_time).' – '.$fmt($this->end_time);
    }

    /** "1 h 30 min" */
    public function durationLabel(): string
    {
        $minutes = (strtotime(substr((string) $this->end_time, 0, 5)) - strtotime(substr((string) $this->start_time, 0, 5))) / 60;
        $h = intdiv((int) $minutes, 60);
        $m = (int) $minutes % 60;

        return trim(($h ? $h.' h ' : '').($m ? $m.' min' : ''));
    }
}
