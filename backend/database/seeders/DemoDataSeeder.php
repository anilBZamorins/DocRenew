<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmployeeProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Reproduces the demo dataset this project has been tested against all
 * along (HR/Manager/Employee logins + employee profiles + a document in
 * every status the UI needs to show) — until now that data only existed
 * ad hoc in whichever sandbox happened to be running, so a fresh local
 * `migrate:fresh --seed` only ever got the bootstrap Admin account from
 * DatabaseSeeder, with nothing to actually click around in.
 *
 * Every seeded user shares the same password (see UserFactory::$password
 * — 'Password123'), matching the login screen's dev-only quick-sign-in
 * dropdown (LoginComponent.DEMO_ACCOUNTS) so the two stay in sync.
 */
class DemoDataSeeder extends Seeder
{
    public function run(?Tenant $tenant = null, ?User $admin = null): void
    {
        $tenant ??= Tenant::first();
        if (! $tenant) {
            $this->command?->warn('DemoDataSeeder: no tenant found — run DatabaseSeeder first.');

            return;
        }

        $passportType = DocumentType::where('tenant_id', $tenant->id)->where('name', 'Passport')->first();
        $visaType = DocumentType::where('tenant_id', $tenant->id)->where('name', 'Visa')->first();
        $licenseType = DocumentType::where('tenant_id', $tenant->id)->where('name', 'Driving License')->first();
        $certType = DocumentType::where('tenant_id', $tenant->id)->where('name', 'Certification')->first();

        // --- HR ---
        $hrProfile = EmployeeProfile::create([
            'tenant_id' => $tenant->id,
            'name' => 'Latha Kumar',
            'employee_code' => 'EMP-0002',
            'department' => 'Human Resources',
            'job_title' => 'HR Manager',
            'contact_email' => 'hr@docrenew.test',
            'active' => true,
        ]);
        $hrUser = User::factory()->hr()->create([
            'tenant_id' => $tenant->id,
            'email' => 'hr@docrenew.test',
            'employee_profile_id' => $hrProfile->id,
        ]);

        // --- Manager + their team ---
        $managerProfile = EmployeeProfile::create([
            'tenant_id' => $tenant->id,
            'name' => 'S. Iyer',
            'employee_code' => 'EMP-0003',
            'department' => 'Engineering',
            'job_title' => 'Engineering Manager',
            'contact_email' => 'manager@docrenew.test',
            'active' => true,
        ]);
        $managerUser = User::factory()->manager()->create([
            'tenant_id' => $tenant->id,
            'email' => 'manager@docrenew.test',
            'employee_profile_id' => $managerProfile->id,
        ]);

        $emp1Profile = EmployeeProfile::create([
            'tenant_id' => $tenant->id,
            'name' => 'M. Chen',
            'employee_code' => 'EMP-0004',
            'department' => 'Engineering',
            'job_title' => 'Software Engineer',
            'manager_id' => $managerProfile->id,
            'contact_email' => 'emp1@docrenew.test',
            'active' => true,
        ]);
        $emp1User = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'emp1@docrenew.test',
            'employee_profile_id' => $emp1Profile->id,
        ]);

        // A second team member with no login of their own yet (invited
        // flow never completed) — exercises has_user_account: false.
        $emp2Profile = EmployeeProfile::create([
            'tenant_id' => $tenant->id,
            'name' => 'R. Das',
            'employee_code' => 'EMP-0005',
            'department' => 'Engineering',
            'job_title' => 'QA Engineer',
            'manager_id' => $managerProfile->id,
            'contact_email' => 'r.das@docrenew.test',
            'active' => true,
        ]);

        // --- Documents covering every status the dashboard computes ---
        Document::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_profile_id' => $emp1Profile->id,
            'document_type_id' => $passportType->id,
            'expiry_date' => now()->addYear()->format('Y-m-d'),
        ]); // VALID

        Document::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_profile_id' => $emp1Profile->id,
            'document_type_id' => $visaType->id,
            'expiry_date' => now()->addDays(60)->format('Y-m-d'),
        ]); // EXPIRING_SOON

        Document::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_profile_id' => $emp2Profile->id,
            'document_type_id' => $licenseType->id,
            'expiry_date' => now()->addDays(4)->format('Y-m-d'),
        ]); // EXPIRING_URGENT

        Document::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_profile_id' => $emp2Profile->id,
            'document_type_id' => $certType->id,
            'expiry_date' => now()->subDays(15)->format('Y-m-d'),
        ]); // EXPIRED — left open, for testing the dismiss-escalation flow

        Document::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_profile_id' => $hrProfile->id,
            'document_type_id' => $passportType->id,
            'expiry_date' => now()->subDays(40)->format('Y-m-d'),
            'escalation_dismissed_at' => now()->subDays(2),
            'escalation_dismissed_reason' => 'Renewal in progress with the embassy; expected within two weeks.',
            'escalation_dismissed_by' => $admin?->id ?? $managerUser->id,
        ]); // EXPIRED with escalation already dismissed

        Document::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_profile_id' => $managerProfile->id,
            'document_type_id' => $licenseType->id,
            'expiry_date' => now()->addYear()->format('Y-m-d'),
            'last_renewed_at' => now()->subDays(5),
        ]); // RENEWED (within the last 30 days)

        $this->command?->info('Seeded demo users: hr@docrenew.test, manager@docrenew.test, emp1@docrenew.test (password: Password123)');
        $this->command?->info('Seeded 5 employee profiles and 6 documents spanning every status.');
    }
}
