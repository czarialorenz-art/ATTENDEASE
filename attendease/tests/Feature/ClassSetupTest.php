<?php

namespace Tests\Feature;

use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** FR-CLS-17: class details + location on Create, the class "…" menu, and the Schedule tab. */
class ClassSetupTest extends TestCase
{
    use RefreshDatabase;

    private function details(array $overrides = []): array
    {
        return [
            'subject' => 'ITE101', 'year_level' => '4th Year', 'section' => 'BSIT-4B', 'school_term' => '2026-2027',
            'semester' => '1st', 'semester_start' => '2026-08-01', 'semester_end' => '2026-12-15', ...$overrides,
        ];
    }

    public function test_fr_cls_17_create_a_class_with_its_location(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->get('/classes/create')->assertOk()
            ->assertSeeInOrder(['Class details', 'name="subject"', 'name="semester_end"', 'Classroom location', 'name="building"', 'name="floor"', 'name="room"'], false);

        $this->actingAs($teacher)->post('/classes', $this->details(['building' => ' Main Building ', 'floor' => '3rd', 'room' => 'Tesla Lab']))
            ->assertSessionHasNoErrors();
        $class = SchoolClass::sole();
        $this->assertSame(['Main Building', '3rd', 'Tesla Lab'], [$class->building, $class->floor, $class->room]);

        // Location is optional; too long is rejected.
        $this->actingAs($teacher)->post('/classes', $this->details(['subject' => 'ITE102']))->assertSessionHasNoErrors();
        $this->assertNull(SchoolClass::where('subject', 'ITE102')->value('building'));
        $this->actingAs($teacher)->post('/classes', $this->details(['subject' => 'ITE103', 'room' => str_repeat('x', 101)]))->assertSessionHasErrors('room');
    }

    public function test_fr_cls_17_edit_details_and_location_from_the_menu(): void
    {
        $class = $this->makeClass(null, ['subject' => 'ITE101', 'section' => 'A']);
        $teacher = $class->teacher;

        // The "…" menu next to the class name, on every class tab.
        foreach (['', '/students', '/sessions', '/report'] as $tab) {
            $this->actingAs($teacher)->get("/classes/{$class->id}{$tab}")->assertOk()
                ->assertSee('data-class-header-menu', false)
                ->assertSee('href="'.route('classes.edit', $class).'"', false)
                ->assertSeeInOrder(['Edit class details', 'Copy class code', '<span id="nav-class-code" class="hidden">'.$class->code.'</span>'], false);
        }

        $this->actingAs($teacher)->get(route('classes.edit', $class))->assertOk()
            ->assertSee('id="class-details"', false)->assertSee('id="class-location"', false)
            ->assertDontSee('Weekly schedule');

        $this->actingAs($teacher)->put(route('classes.update', $class), $this->details(['section' => 'B']))
            ->assertRedirect(route('classes.edit', $class));
        $this->actingAs($teacher)->put(route('classes.location', $class), ['building' => 'Annex', 'floor' => '', 'room' => '204'])
            ->assertRedirect(route('classes.edit', $class));
        $class->refresh();
        $this->assertSame(['B', 'Annex', null, '204'], [$class->section, $class->building, $class->floor, $class->room]);
    }

    public function test_fr_cls_17_schedule_tab_holds_only_the_schedule(): void
    {
        $class = $this->makeClass();
        ClassSchedule::factory()->create(['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '09:30:00']);

        $page = $this->actingAs($class->teacher)->get(route('classes.schedule', $class))->assertOk()
            ->assertSee('Weekly schedule')->assertSee('Monday')->assertSee('+ Add schedule')
            ->assertDontSee('id="class-details"', false)->assertDontSee('Classroom location')->assertDontSee('name="building"', false)
            ->assertSee('<a href="'.route('classes.schedule', $class).'" class="tab-active">', false);
        $this->assertStringContainsString('Schedule', $page->getContent());
        $page->assertDontSee('Schedule &amp; location', false);

        // The no-overlap rule still applies here.
        $this->actingAs($class->teacher)->post(route('schedules.store', $class), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'])
            ->assertSessionHasErrors();
        $this->actingAs($class->teacher)->post(route('schedules.store', $class), ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00'])
            ->assertRedirect(route('classes.schedule', $class));
    }

    public function test_fr_cls_17_dashboard_menu_has_edit_details_and_copy_code(): void
    {
        $class = $this->makeClass(null, ['subject' => 'ITE101']);

        $this->actingAs($class->teacher)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Open class', 'Edit class details', 'Copy class code', 'Delete class'])
            ->assertSee('<span id="class-code-'.$class->id.'" class="hidden">'.$class->code.'</span>', false)
            ->assertDontSee('Edit details &amp; schedule', false);
    }

    public function test_fr_cls_17_another_teacher_or_a_student_gets_403(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class);

        foreach ([$this->makeClass()->teacher, $student] as $user) {
            $this->actingAs($user)->get(route('classes.edit', $class))->assertForbidden();
            $this->actingAs($user)->get(route('classes.schedule', $class))->assertForbidden();
            $this->actingAs($user)->put(route('classes.location', $class), ['room' => 'x'])->assertForbidden();
        }
    }
}
