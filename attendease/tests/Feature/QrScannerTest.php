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

    /** Students scan from the top bar's Scan QR button; the dashboard has no separate scan card. */
    public function test_scanner_is_linked_from_the_top_bar_only(): void
    {
        $class = $this->makeClass();
        $student = $this->enroll($class);

        foreach ([$student, User::factory()->create()] as $user) { // in a class, and in none
            $this->actingAs($user)->get('/dashboard')->assertOk()
                ->assertSee('Scan QR')
                ->assertSee('href="'.route('scanner').'"', false)
                ->assertDontSee('Open the camera and scan the code');
        }
        $this->actingAs($student)->get('/scanner')->assertOk();
    }

    public function test_expired_code_offers_scan_again(): void
    {
        $this->actingAs(User::factory()->create())->get('/scan?t=expired.token')
            ->assertSee('This QR code has expired.')
            ->assertSee('Scan again')
            ->assertSee('href="'.route('scanner').'"', false);
    }
}
