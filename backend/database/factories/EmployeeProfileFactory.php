<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'employee_code' => fake()->unique()->bothify('EMP-####'),
            'department' => fake()->randomElement(['Engineering', 'HR', 'Finance', 'Operations']),
            'job_title' => fake()->jobTitle(),
            'contact_email' => fake()->unique()->safeEmail(),
            'active' => true,
        ];
    }
}
