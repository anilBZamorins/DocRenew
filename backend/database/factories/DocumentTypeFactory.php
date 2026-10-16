<?php

namespace Database\Factories;

use App\Models\DocumentType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'category' => 'EMPLOYEE_DOCUMENT',
            'sort_order' => 0,
            'retired' => false,
        ];
    }

    public function extendedItem(): static
    {
        return $this->state(fn () => ['category' => 'EXTENDED_ITEM']);
    }
}
