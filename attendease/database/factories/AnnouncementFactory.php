<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'author_id' => fn (array $a) => SchoolClass::find($a['class_id'])->teacher_id,
            'type' => Announcement::GENERAL,
            'title' => 'Bring your laptops',
            'body' => 'We will do a hands-on lab next meeting.',
            'cancelled_date' => null,
        ];
    }

    public function cancellation(?string $date = null): static
    {
        return $this->state([
            'type' => Announcement::CANCELLATION,
            'title' => 'Class cancelled',
            'body' => 'Faculty meeting.',
            'cancelled_date' => $date ?? now()->addDays(2)->toDateString(),
        ]);
    }
}
