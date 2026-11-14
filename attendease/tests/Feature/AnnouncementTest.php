<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AttendanceSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** FR-ANN: class announcements and class cancellations. */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->freezeManila('2026-11-14 09:00:00');
        $this->class = $this->makeClass(null, ['subject' => 'SYSADMIN', 'section' => 'A & B']);
        $this->student = $this->enroll($this->class);
    }

    private function postAnnouncement(array $data)
    {
        return $this->actingAs($this->class->teacher)->post("/classes/{$this->class->id}/announcements", $data);
    }

    public function test_fr_ann_01_teacher_posts_an_announcement_and_students_are_notified(): void
    {
        $pending = $this->enroll($this->class, null, 'pending');

        $this->actingAs($this->class->teacher)->get("/classes/{$this->class->id}/announcements")->assertOk()
            ->assertSee('Post to the class')->assertSee('Class cancellation');

        $this->postAnnouncement(['type' => 'general', 'title' => 'Bring laptops', 'body' => 'Hands-on lab on Monday.'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Announcement posted. Your students were notified.');

        $a = Announcement::sole();
        $this->assertSame(['general', 'Bring laptops', $this->class->teacher->id], [$a->type, $a->title, $a->author_id]);
        Notification::assertSentTo($this->student, AnnouncementPosted::class, fn ($n, $ch) => $ch === ['database', 'mail']);
        Notification::assertNotSentTo([$pending, $this->class->teacher], AnnouncementPosted::class);
    }

    public function test_fr_ann_01_validation(): void
    {
        $this->postAnnouncement(['type' => 'general', 'title' => '', 'body' => ''])->assertSessionHasErrors(['title', 'body']);
        $this->postAnnouncement(['type' => 'news', 'title' => 'x', 'body' => 'y'])->assertSessionHasErrors('type');
        $this->postAnnouncement(['type' => 'cancellation'])->assertSessionHasErrors(['cancelled_date' => 'Choose the date of the class that is cancelled.']);
        $this->postAnnouncement(['type' => 'cancellation', 'cancelled_date' => '2026-11-13'])->assertSessionHasErrors(['cancelled_date' => 'The cancelled date must be today or later.']);
        $this->assertSame(0, Announcement::count());
    }

    public function test_fr_ann_02_cancellation_defaults_its_title_and_tells_students(): void
    {
        $this->postAnnouncement(['type' => 'cancellation', 'cancelled_date' => '2026-11-16', 'body' => 'Faculty meeting.'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Cancellation posted for Nov 16, 2026. Your students were notified.');

        $a = Announcement::sole();
        $this->assertSame(['cancellation', 'Class cancelled', '2026-11-16'], [$a->type, $a->title, $a->cancelled_date->toDateString()]);

        Notification::assertSentTo($this->student, AnnouncementPosted::class, function ($n) {
            $data = $n->toArray($this->student);

            return $data['title'] === 'Class cancelled: SYSADMIN · A & B on Mon, Nov 16'
                && str_contains($data['message'], 'will not meet on Monday, November 16, 2026. Faculty meeting.');
        });

        // Only one cancellation per date.
        $this->postAnnouncement(['type' => 'cancellation', 'cancelled_date' => '2026-11-16'])->assertSessionHasErrors(['cancelled_date' => 'You already cancelled class on that date.']);
    }

    public function test_fr_ann_03_students_see_announcements_on_their_class_page_and_dashboard(): void
    {
        Announcement::factory()->for($this->class, 'schoolClass')->create(['title' => 'Quiz moved to Friday', 'created_at' => now()->subDays(2)]);
        Announcement::factory()->for($this->class, 'schoolClass')->create(['title' => 'Old News', 'created_at' => now()->subDays(20)]);
        Announcement::factory()->for($this->class, 'schoolClass')->cancellation('2026-11-15')->create(['created_at' => now()->subDays(10)]);
        $otherClass = $this->makeClass(null, ['subject' => 'NOTMINE']);
        Announcement::factory()->for($otherClass, 'schoolClass')->create(['title' => 'Not For You']);

        $this->actingAs($this->student)->get("/classes/{$this->class->id}")->assertOk()
            ->assertSee('Quiz moved to Friday')->assertSee('Old News')
            ->assertSee('No class on Sunday, November 15, 2026')->assertSee('(tomorrow)');

        $dash = $this->actingAs($this->student)->get('/dashboard')->assertOk();
        // Upcoming cancellation first (even though it was posted earlier), then this week's posts; old news and other classes left out.
        $this->assertSame(['Class cancelled', 'Quiz moved to Friday'], $dash->viewData('myAnnouncements')->pluck('title')->all());
        $dash->assertSee('No class on Sun, Nov 15')->assertDontSee('Old News')->assertDontSee('Not For You');
    }

    public function test_fr_ann_04_sessions_on_a_cancelled_date_are_flagged_to_the_teacher(): void
    {
        $existing = AttendanceSession::factory()->for($this->class, 'schoolClass')->create(['date' => '2026-11-16']);

        $this->postAnnouncement(['type' => 'cancellation', 'cancelled_date' => '2026-11-16'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'you have 1 attendance session on that date'));

        $this->actingAs($this->class->teacher)->get("/sessions/{$existing->id}")
            ->assertSee('You announced that class is cancelled on this date.');

        $this->actingAs($this->class->teacher)->post("/classes/{$this->class->id}/sessions", ['date' => '2026-11-16'])
            ->assertSessionHas('status', 'Session created. Note: you announced that class is cancelled on Nov 16, 2026.');
    }

    public function test_fr_ann_05_teacher_edits_and_deletes(): void
    {
        $a = Announcement::factory()->for($this->class, 'schoolClass')->create();

        $this->actingAs($this->class->teacher)->get("/announcements/{$a->id}/edit")->assertOk()->assertSee('Bring your laptops');
        $this->actingAs($this->class->teacher)->put("/announcements/{$a->id}", ['type' => 'general', 'title' => 'Updated', 'body' => 'New details.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Updated', $a->fresh()->title);
        Notification::assertNothingSent(); // edits are quiet

        $this->actingAs($this->class->teacher)->delete("/announcements/{$a->id}")->assertRedirect();
        $this->assertSame(0, Announcement::count());
    }

    public function test_only_the_classs_teacher_can_post_edit_or_delete(): void
    {
        $a = Announcement::factory()->for($this->class, 'schoolClass')->create();

        foreach ([$this->student, User::factory()->create()] as $user) {
            $this->actingAs($user)->get("/classes/{$this->class->id}/announcements")->assertForbidden();
            $this->actingAs($user)->post("/classes/{$this->class->id}/announcements", ['type' => 'general', 'title' => 'x', 'body' => 'y'])->assertForbidden();
            $this->actingAs($user)->get("/announcements/{$a->id}/edit")->assertForbidden();
            $this->actingAs($user)->put("/announcements/{$a->id}", ['type' => 'general', 'title' => 'x', 'body' => 'y'])->assertForbidden();
            $this->actingAs($user)->delete("/announcements/{$a->id}")->assertForbidden();
        }
        $this->assertSame('Bring your laptops', $a->fresh()->title);

        // Outsiders can't read a class's announcements either.
        $this->actingAs(User::factory()->create())->get("/classes/{$this->class->id}")->assertForbidden();
    }
}
