<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Student lists are alphabetical by surname and show "Surname, Given names". */
class StudentNameOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_name_is_split_into_surname_given_names_and_suffix(): void
    {
        $list = fn (string $name) => (new User(['name' => $name]))->listName();

        $this->assertSame('Loreno, Maria Teresa A.', $list('Maria Teresa A. Loreno'));
        $this->assertSame('Dela Cruz, Juan Miguel Jr.', $list('Juan Miguel Dela Cruz Jr.'));
        $this->assertSame('De los Santos, Ana', $list('Ana De los Santos'));
        $this->assertSame('Santos, Carlo III', $list('Carlo Santos III'));
        $this->assertSame('Lim, Ben', $list('  Ben   Lim '));
        $this->assertSame('Cher', $list('Cher'));
        $this->assertSame('Cruz, Dela', $list('Dela Cruz')); // at least one given name is kept
        $this->assertSame('Loreno', (new User(['name' => 'Maria Teresa A. Loreno']))->lastName());
    }

    public function test_students_page_session_page_and_report_list_students_by_surname(): void
    {
        $class = $this->makeClass();
        foreach (['Zed Abad', 'Ana Santos', 'Juan Dela Cruz', 'Bea Cruz', 'Ana Abad'] as $name) {
            $this->enroll($class, User::factory()->create(['name' => $name]));
        }
        $expected = ['Abad, Ana', 'Abad, Zed', 'Cruz, Bea', 'Dela Cruz, Juan', 'Santos, Ana'];
        $teacher = $class->teacher;

        $this->actingAs($teacher)->get("/classes/{$class->id}/students")->assertOk()->assertSeeInOrder($expected);

        $session = AttendanceSession::factory()->for($class, 'schoolClass')->create(['date' => today(), 'time_in_opened_at' => now()]);
        $this->actingAs($teacher)->get("/sessions/{$session->id}")->assertOk()->assertSeeInOrder($expected);

        $res = $this->actingAs($teacher)->get("/classes/{$class->id}/report?period=day&date=".today()->toDateString())->assertOk();
        $res->assertSeeInOrder($expected);
        $this->assertSame($expected, collect($res->viewData('rows'))->map(fn ($r) => $r['student']->listName())->all());
        $html = view('pdf.attendance-report', $res->original->getData())->render();
        $positions = array_map(fn ($n) => strpos($html, '<td class="name">'.$n), $expected);
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());
    }
}
