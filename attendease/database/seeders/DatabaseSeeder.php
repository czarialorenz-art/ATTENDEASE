<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates one sign-in for local testing: test@<ALLOWED_EMAIL_DOMAIN> / password */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'id_number' => '0000-00000',
            'email' => 'test@'.config('app.allowed_email_domain'),
        ]);
    }
}
