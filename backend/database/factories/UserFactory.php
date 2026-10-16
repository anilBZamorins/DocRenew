<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory (plain text — the
     * 'password' => 'hashed' cast on the model bcrypts it on assignment).
     */
    protected static ?string $password = 'Password123';

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password,
            'role' => UserRole::Employee,
            'status' => UserStatus::Active,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function hr(): static
    {
        return $this->state(fn () => ['role' => UserRole::Hr]);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => UserRole::Manager]);
    }

    public function invited(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Invited]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}
