<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\EmailChangedNotice;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SecurityAlert;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function signup(array $overrides = [])
    {
        return $this->post('/register', array_merge([
            'name' => 'Juan Santos',
            'id_number' => '2023-00123',
            'email' => 'juan.santos@mqcqc.ph.education',
            'password' => 'Secret-Pass1',
        ], $overrides));
    }

    /* ---------- Sign-up ---------- */

    public function test_fr_auth_01_signup_form_has_the_four_fields_and_no_role(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        foreach (['name="name"', 'name="id_number"', 'name="email"', 'name="password"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $this->assertStringNotContainsStringIgnoringCase('name="role"', $html);
    }

    public function test_fr_auth_02_non_school_email_is_rejected(): void
    {
        Notification::fake();

        foreach (['juan@gmail.com', 'juan@mqcqc.ph.education.evil.com', 'juan@xmqcqc.ph.education'] as $email) {
            $this->signup(['email' => $email])->assertSessionHasErrors('email');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_fr_auth_02_school_email_is_trimmed_and_case_insensitive(): void
    {
        Notification::fake();

        $this->signup(['email' => '  Juan.Santos@MQCQC.PH.Education '])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('users', ['email' => 'juan.santos@mqcqc.ph.education']);
    }

    public function test_fr_auth_03_email_and_id_number_are_unique(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'juan.santos@mqcqc.ph.education', 'id_number' => '2023-00999']);
        User::factory()->create(['id_number' => '2023-00123']);

        $this->signup()->assertSessionHasErrors(['email', 'id_number']);
    }

    public function test_fr_auth_04_password_minimum_8_and_is_hashed(): void
    {
        Notification::fake();
        $this->signup(['password' => 'short'])->assertSessionHasErrors('password');

        // Each missing kind of character is reported (8+ chars, upper, lower, number, symbol).
        $cases = [
            'Ab1!' => 'The password field must be at least 8 characters.',
            'alllower1!' => 'The password field must contain at least one uppercase and one lowercase letter.',
            'ALLUPPER1!' => 'The password field must contain at least one uppercase and one lowercase letter.',
            'NoNumbers!' => 'The password field must contain at least one number.',
            'NoSymbol12' => 'The password field must contain at least one symbol.',
            '12345678!!' => 'The password field must contain at least one letter.',
        ];
        foreach ($cases as $weak => $message) {
            $this->signup(['password' => $weak])->assertSessionHasErrors(['password' => $message]);
        }
        $this->signup(['password' => str_repeat('Aa1!', 17)])->assertSessionHasErrors('password'); // over 64
        $this->assertDatabaseCount('users', 0);

        $this->signup()->assertRedirect();
        $user = User::first();
        $this->assertNotSame('Secret-Pass1', $user->password);
        $this->assertTrue(Hash::check('Secret-Pass1', $user->password));
    }

    public function test_fr_auth_05_signup_signs_in_and_queues_welcome_email(): void
    {
        Notification::fake();

        $this->signup()->assertRedirect('/dashboard');

        $user = User::first();
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, WelcomeNotification::class);
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldQueue::class, class_implements(WelcomeNotification::class));
    }

    /* ---------- Login ---------- */

    public function test_fr_auth_06_login_with_remember_me(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $res = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);

        $res->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
    }

    public function test_fr_auth_07_sixth_failed_attempt_is_blocked_with_timer(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('login');
        }

        // Even the right password is refused while blocked.
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'Too many login attempts. Try again in 60 seconds.']);
        $this->assertGuest();

        $this->travel(61)->seconds();
        Notification::fake();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_fr_auth_07_success_clears_the_counter(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/logout');

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_fr_auth_08_error_does_not_reveal_whether_email_exists(): void
    {
        $user = User::factory()->create();

        $same = ['login' => 'These credentials do not match our records.'];

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors($same);
        $this->post('/login', ['email' => 'nobody@mqcqc.ph.education', 'password' => 'wrong'])->assertSessionHasErrors($same);
    }

    public function test_fr_auth_09_new_device_sends_alert_known_device_does_not(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        Notification::assertSentTo($user, SecurityAlert::class, fn ($n) => $n->type === SecurityAlert::NEW_SIGN_IN);
        $this->assertDatabaseCount('user_devices', 1);
        $this->post('/logout');

        Notification::fake();
        $token = str_repeat('k', 64);
        UserDevice::factory()->for($user)->create(['token_hash' => hash('sha256', $token)]);

        $this->withCookie('ae_dt', $token)->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        Notification::assertNotSentTo($user, SecurityAlert::class);
    }

    /* ---------- Forgot / reset ---------- */

    public function test_fr_auth_10_forgot_password_response_is_generic(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $a = $this->post('/forgot-password', ['email' => $user->email])->getSession()->get('status');
        $b = $this->post('/forgot-password', ['email' => 'ghost@mqcqc.ph.education'])->getSession()->get('status');

        $this->assertSame($a, $b);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    private function requestToken(User $user): string
    {
        $token = null;
        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_fr_auth_11_token_is_hashed_single_use_and_replaced(): void
    {
        $user = User::factory()->create();
        $first = $this->requestToken($user);
        $second = $this->requestToken($user);

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotSame($second, $row->token);
        $this->assertTrue(Hash::check($second, $row->token));

        // The older token was replaced.
        $this->post('/reset-password', ['token' => $first, 'email' => $user->email, 'password' => 'New-Password1!', 'password_confirmation' => 'New-Password1!'])
            ->assertSessionHasErrors('email');

        $this->post('/reset-password', ['token' => $second, 'email' => $user->email, 'password' => 'New-Password1!', 'password_confirmation' => 'New-Password1!'])
            ->assertRedirect('/login');

        // Single use.
        $this->post('/reset-password', ['token' => $second, 'email' => $user->email, 'password' => 'New-Password2!', 'password_confirmation' => 'New-Password2!'])
            ->assertSessionHasErrors('email');
    }

    public function test_fr_auth_11_token_expires_after_15_minutes(): void
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->travel(16)->minutes();

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'New-Password1!', 'password_confirmation' => 'New-Password1!'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_fr_auth_12_reset_logs_out_all_sessions_rotates_token_and_alerts(): void
    {
        $user = User::factory()->create();
        $oldRemember = $user->remember_token;
        DB::table('sessions')->insert([
            ['id' => 'phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'laptop', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);
        $token = $this->requestToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'New-Password1!', 'password_confirmation' => 'New-Password1!'])
            ->assertRedirect('/login');

        $user->refresh();
        $this->assertTrue(Hash::check('New-Password1!', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertGuest();
        Notification::assertSentTo($user, SecurityAlert::class, fn ($n) => $n->type === SecurityAlert::PASSWORD_CHANGED);
    }

    /* ---------- Change password ---------- */

    public function test_fr_auth_13_change_password_needs_current_and_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/password', ['current_password' => 'wrong', 'password' => 'New-Password1!', 'password_confirmation' => 'New-Password1!'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'New-Password1!', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
    }

    public function test_fr_auth_04_same_rule_applies_to_reset_and_change_password(): void
    {
        $user = User::factory()->create();

        // Reset (signed out)
        $token = $this->requestToken($user);
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'weakpassword', 'password_confirmation' => 'weakpassword'])
            ->assertSessionHasErrors('password');

        // Change (signed in)
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'weakpassword', 'password_confirmation' => 'weakpassword'])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_fields_have_a_show_toggle_and_new_passwords_a_checklist(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('data-toggle-password="f_password"', false)
            ->assertSee('A special character', false)
            ->assertSee('An uppercase letter', false);

        $this->get('/login')->assertOk()
            ->assertSee('data-toggle-password="f_password"', false)
            ->assertDontSee('A special character', false); // login just checks the password

        $user = User::factory()->create();
        $this->actingAs($user)->get('/password')->assertOk()
            ->assertSee('data-toggle-password="f_current_password"', false)
            ->assertSee('data-password-rules="f_password_rules"', false);
    }

    public function test_fr_auth_14_change_password_logs_out_others_keeps_current_and_alerts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'New-Password1!', 'password_confirmation' => 'New-Password1!'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('New-Password1!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, SecurityAlert::class, fn ($n) => $n->type === SecurityAlert::PASSWORD_CHANGED);
    }

    /* ---------- Profile ---------- */

    public function test_fr_auth_15_profile_edit_with_optional_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/profile', [
            'name' => 'New Name', 'email' => $user->email, 'id_number' => $user->id_number,
            'course' => 'BSIT', 'year' => '2', 'section' => 'A', 'department' => '',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'course' => 'BSIT', 'department' => null]);

        $this->actingAs($user)->put('/profile', ['name' => '', 'email' => '', 'id_number' => ''])
            ->assertSessionHasErrors(['name', 'id_number'])->assertSessionDoesntHaveErrors('email'); // email is optional (FR-ADM-13)
    }

    public function test_fr_auth_16_email_change_checks_domain_and_alerts_both_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@mqcqc.ph.education']);
        User::factory()->create(['email' => 'taken@mqcqc.ph.education']);

        $base = ['name' => $user->name, 'id_number' => $user->id_number];
        $this->actingAs($user)->put('/profile', $base + ['email' => 'me@gmail.com'])->assertSessionHasErrors('email');
        $this->actingAs($user)->put('/profile', $base + ['email' => 'taken@mqcqc.ph.education'])->assertSessionHasErrors('email');

        $this->actingAs($user)->put('/profile', $base + ['email' => 'new@mqcqc.ph.education'])->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(SecurityAlert::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'old@mqcqc.ph.education' && $n->type === SecurityAlert::EMAIL_CHANGED);
        Notification::assertSentTo($user->fresh(), EmailChangedNotice::class);
    }

    public function test_fr_auth_17_id_number_stays_unique_on_edit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->put('/profile', ['name' => $user->name, 'email' => $user->email, 'id_number' => $other->id_number])
            ->assertSessionHasErrors('id_number');
        $this->actingAs($user)->put('/profile', ['name' => $user->name, 'email' => $user->email, 'id_number' => $user->id_number])
            ->assertSessionHasNoErrors();
    }
}
