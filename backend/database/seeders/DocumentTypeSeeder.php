<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * FSD 4.5 / db/schema.sql's commented seed block: every tenant gets this
 * default catalog of document types so document creation isn't blocked
 * on an admin manually defining types first.
 */
class DocumentTypeSeeder extends Seeder
{
    private const EMPLOYEE_DOCUMENT_TYPES = [
        'Passport', 'Visa', 'Work Permit', 'Driving License', 'Certification',
    ];

    private const EXTENDED_ITEM_TYPES = [
        'Warranty', 'Vehicle Maintenance', 'Bill', 'AMC', 'Other Notification',
    ];

    public function run(?Tenant $tenant = null): void
    {
        $tenants = $tenant ? collect([$tenant]) : Tenant::all();

        foreach ($tenants as $t) {
            $order = 0;

            foreach (self::EMPLOYEE_DOCUMENT_TYPES as $name) {
                DocumentType::firstOrCreate(
                    ['tenant_id' => $t->id, 'name' => $name],
                    ['category' => 'EMPLOYEE_DOCUMENT', 'sort_order' => $order++, 'retired' => false]
                );
            }

            foreach (self::EXTENDED_ITEM_TYPES as $name) {
                DocumentType::firstOrCreate(
                    ['tenant_id' => $t->id, 'name' => $name],
                    ['category' => 'EXTENDED_ITEM', 'sort_order' => $order++, 'retired' => false]
                );
            }
        }
    }
}
