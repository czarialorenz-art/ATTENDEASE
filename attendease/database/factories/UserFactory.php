<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'id_number' => fake()->unique()->numerify('20##-#####'),
            'email' => Str::lower(fake()->unique()->userName()).'@'.config('app.allowed_email_domain'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => User::STUDENT,
        ];
    }

    public function student(): static
    {
        return $this->state(fn () => ['role' => User::STUDENT]);
    }

    public function teacher(): static
    {
        return $this->state(fn () => ['role' => User::TEACHER]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ADMIN]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['deactivated_at' => now()]);
    }
}
