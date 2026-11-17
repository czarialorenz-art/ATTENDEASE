<?php

namespace Tests\Feature;

use App\Models\ClassSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-CLS-12: the teacher adds, edits and removes a class's weekly schedules. */
class ClassScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function add($class, array $data)
    {
        return $this->actingAs($class->teacher)->post("/classes/{$class->id}/schedules", $data);
    }

    public function test_teacher_adds_schedules_and_sees_them_listed(): void
    {
        $class = $this->makeClass();

        $this->actingAs($class->teacher)->get("/classes/{$class->id}/edit")->assertOk()
            ->assertSee('No schedules yet.')->assertSee('Add a schedule')->assertSee('+ Add schedule');

        $this->add($class, ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:30'])
            ->assertRedirect(route('classes.edit', $class))->assertSessionHas('status', 'Schedule added: Monday 8:00 AM – 9:30 AM.');
        $this->add($class, ['day_of_week' => 3, 'start_time' => '13:00', 'end_time' => '16:00'])->assertSessionHasNoErrors();

        $this->assertSame([[1, '08:00:00', '09:30:00'], [3, '13:00:00', '16:00:00']],
            $class->schedules()->get()->map(fn ($s) => [$s->day_of_week, $s->start_time, $s->end_time])->all());

        $this->actingAs($class->teacher)->get("/classes/{$class->id}/edit")
            ->assertSeeInOrder(['Monday', '8:00 AM – 9:30 AM', '1 h 30 min', 'Wednesday', '1:00 PM – 4:00 PM', '3 h'])
            ->assertSee('2 schedules');
        $this->actingAs($class->teacher)->get("/classes/{$class->id}")->assertSee('Monday · 8:00 AM – 9:30 AM')->assertSee('Manage schedule');
    }

    public function test_schedule_validation(): void
    {
        $class = $this->makeClass();

        $this->add($class, [])->assertSessionHasErrorsIn('addSchedule', ['day_of_week', 'start_time', 'end_time']);
        $this->add($class, ['day_of_week' => 7, 'start_time' => '8am', 'end_time' => '09:00'])->assertSessionHasErrorsIn('addSchedule', ['day_of_week', 'start_time']);
        $this->add($class, ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '09:00'])
            ->assertSessionHasErrorsIn('addSchedule', ['end_time' => 'The end time must be after the start time.']);
        $this->assertSame(0, $class->schedules()->count());

        // The error shows on the Add form only.
        $this->add($class, ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '09:00']);
        $this->actingAs($class->teacher)->get("/classes/{$class->id}/edit")->assertSee('The end time must be after the start time.');
    }

    public function test_schedules_cannot_overlap_this_class_or_the_teachers_other_classes(): void
    {
        $teacher = User::factory()->teacher()->create();
        $class = $this->makeClass($teacher, ['subject' => 'CAPSTONE 2', 'section' => 'A']);
        $other = $this->makeClass($teacher, ['subject' => 'ITE101', 'section' => 'B']);
        $this->add($class, ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '10:00'])->assertSessionHasNoErrors();
        $this->add($other, ['day_of_week' => 4, 'start_time' => '13:00', 'end_time' => '15:00'])->assertSessionHasNoErrors();

        $this->add($class, ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00'])
            ->assertSessionHasErrorsIn('addSchedule', ['start_time' => 'This overlaps this class\'s Tuesday 8:00 AM – 10:00 AM schedule.']);
        $this->add($class, ['day_of_week' => 4, 'start_time' => '14:00', 'end_time' => '16:00'])
            ->assertSessionHasErrorsIn('addSchedule', ['start_time' => 'This overlaps your class ITE101 · B (Thursday 1:00 PM – 3:00 PM).']);

        // Back-to-back is fine, and so is another teacher's class or a class in a different semester.
        $this->add($class, ['day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '11:30'])->assertSessionHasNoErrors();
        $this->add($class, ['day_of_week' => 2, 'start_time' => '07:00', 'end_time' => '08:00'])->assertSessionHasNoErrors();
        $someoneElse = $this->makeClass();
        $this->add($someoneElse, ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '10:00'])->assertSessionHasNoErrors();
        $nextSem = $this->makeClass($teacher, ['semester_start' => now()->addMonths(6)->toDateString(), 'semester_end' => now()->addMonths(10)->toDateString()]);
        $this->add($nextSem, ['day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '10:00'])->assertSessionHasNoErrors();

        // An older schedule stored without seconds is compared correctly too.
        ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 5, 'start_time' => '09:30', 'end_time' => '11:00']);
        $this->add($class, ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '09:30'])->assertSessionHasNoErrors();
    }

    public function test_teacher_edits_a_schedule(): void
    {
        $class = $this->makeClass();
        $this->add($class, ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:30']);
        $this->add($class, ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '14:00']);
        [$morning, $afternoon] = $class->schedules()->get()->all();

        $this->actingAs($class->teacher)->put("/schedules/{$morning->id}", ['day_of_week' => 5, 'start_time' => '07:30', 'end_time' => '09:00'])
            ->assertRedirect(route('classes.edit', $class))->assertSessionHas('status', 'Schedule updated: Friday 7:30 AM – 9:00 AM.');
        $this->assertSame([5, '07:30:00', '09:00:00'], [$morning->fresh()->day_of_week, $morning->fresh()->start_time, $morning->fresh()->end_time]);

        // Editing a schedule doesn't clash with itself, but does with the others.
        $this->actingAs($class->teacher)->put("/schedules/{$afternoon->id}", ['day_of_week' => 1, 'start_time' => '13:30', 'end_time' => '14:30'])->assertSessionHasNoErrors();
        // (Checked on the page: assertSessionHasErrorsIn would use up the flashed error before the next request.)
        $this->actingAs($class->teacher)->put("/schedules/{$afternoon->id}", ['day_of_week' => 5, 'start_time' => '08:00', 'end_time' => '10:00'])
            ->assertRedirect();
        $this->assertSame('13:30:00', $afternoon->fresh()->start_time);

        // The failed edit form reopens with its error; the others stay closed.
        $html = $this->actingAs($class->teacher)->get("/classes/{$class->id}/edit")->getContent();
        $this->assertStringContainsString('This overlaps this class&#039;s Friday 7:30 AM – 9:00 AM schedule.', $html);
        $this->assertMatchesRegularExpression('/id="edit-schedule-'.$afternoon->id.'"\s+class="mt-3 space-y-3 rounded-lg bg-slate-50 p-3"/', $html);
        $this->assertMatchesRegularExpression('/id="edit-schedule-'.$morning->id.'"\s+class="mt-3 space-y-3 rounded-lg bg-slate-50 p-3 hidden"/', $html);
    }

    public function test_teacher_removes_a_schedule(): void
    {
        $class = $this->makeClass();
        $this->add($class, ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:30']);
        $schedule = $class->schedules()->first();

        $this->actingAs($class->teacher)->get("/classes/{$class->id}/edit")->assertSee('data-confirm="Remove the Monday 8:00 AM – 9:30 AM schedule?', false);
        $this->actingAs($class->teacher)->delete("/schedules/{$schedule->id}")
            ->assertRedirect(route('classes.edit', $class))->assertSessionHas('status', 'Schedule removed: Monday 8:00 AM – 9:30 AM.');
        $this->assertSame(0, $class->schedules()->count());
    }

    public function test_only_the_classs_teacher_can_change_its_schedules(): void
    {
        $class = $this->makeClass();
        $schedule = ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:00']);
        $data = ['day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '11:00'];

        foreach ([User::factory()->teacher()->create(), $this->enroll($class), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post("/classes/{$class->id}/schedules", $data)->assertForbidden();
            $this->actingAs($user)->put("/schedules/{$schedule->id}", $data)->assertForbidden();
            $this->actingAs($user)->delete("/schedules/{$schedule->id}")->assertForbidden();
        }
        $this->assertSame([1, 1], [$class->schedules()->count(), $schedule->fresh()->day_of_week]);
    }
}
