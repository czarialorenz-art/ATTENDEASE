<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** In-app camera QR scanner for attendance. */
class QrScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_page_requires_login(): void
    {
        $this->get('/scanner')->assertRedirect('/login');
    }

    public function test_scanner_page_renders_camera_view_pointing_at_scan_route(): void
    {
        $this->actingAs(User::factory()->create())->get('/scanner')->assertOk()
            ->assertSee('Scan attendance QR')
            ->assertSee('id="qr-scanner"', false)
            ->assertSee('data-scan-url="'.route('scan').'"', false)
            ->assertSee('playsinline', false);
    }

    public function test_scanner_is_linked_from_top_bar_and_student_dashboard(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class);

        $this->actingAs($student)->get('/dashboard')->assertOk()
            ->assertSee('href="'.route('scanner').'"', false)
            ->assertSee('Open the camera and scan the code');

        // Someone in no classes still gets the top-bar button, but not the dashboard card.
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertSee('Scan QR')->assertDontSee('Open the camera and scan the code');
    }

    public function test_expired_code_offers_scan_again(): void
    {
        $this->actingAs(User::factory()->create())->get('/scan?t=expired.token')
            ->assertSee('This QR code has expired.')
            ->assertSee('Scan again')
            ->assertSee('href="'.route('scanner').'"', false);
    }
}
