<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmployeeProfile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'employee_profile_id' => EmployeeProfile::factory(),
            'document_type_id' => DocumentType::factory(),
            'category' => 'EMPLOYEE_DOCUMENT',
            'document_number' => fake()->bothify('??######'),
            'issue_date' => fake()->dateTimeBetween('-3 years', '-1 year')->format('Y-m-d'),
            'expiry_date' => fake()->dateTimeBetween('now', '+2 years')->format('Y-m-d'),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expiry_date' => now()->subDays(10)->format('Y-m-d')]);
    }

    public function expiringUrgent(): static
    {
        return $this->state(fn () => ['expiry_date' => now()->addDays(3)->format('Y-m-d')]);
    }

    public function extendedItem(): static
    {
        return $this->state(fn () => [
            'category' => 'EXTENDED_ITEM',
            'employee_profile_id' => null,
            'item_label' => fake()->words(3, true),
        ]);
    }
}
