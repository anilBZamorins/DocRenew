<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds one demo tenant with a bootstrap Admin account, so local/dev
     * environments have a first login without a manual INSERT. Credentials
     * are printed to the console rather than hardcoded in docs.
     */
    public function run(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'DocRenew Demo Org']);

        $admin = User::factory()->admin()->create([
            'tenant_id' => $tenant->id,
            'email' => 'admin@docrenew.test',
            'password' => 'Password123',
        ]);

        $this->call(DocumentTypeSeeder::class, false, ['tenant' => $tenant]);

        $this->command?->info("Seeded tenant '{$tenant->name}' ({$tenant->id})");
        $this->command?->info("Admin login: {$admin->email} / Password123  [tenant_id: {$tenant->id}]");
    }
}
