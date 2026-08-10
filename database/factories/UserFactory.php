<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::User,
            'is_active' => true,
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(Role $role, ?Office $office = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
            'office_id' => $office?->id,
        ]);
    }

    public function admin(?Office $office = null): static
    {
        return $this->role(Role::Admin, $office ?? Office::factory()->createOne());
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SuperAdmin);
    }

    /** A staff account attached to an office -- the ordinary case. */
    public function staff(?Office $office = null): static
    {
        return $this->role(Role::User, $office ?? Office::factory()->createOne());
    }

    /** Self-registered and not yet assigned an office. */
    public function quarantined(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::User,
            'office_id' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
