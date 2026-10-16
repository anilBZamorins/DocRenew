<?php

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStage;
use App\Models\ReminderSchedule;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * FSD 4.5: "Reminder schedule: tenant-wide default stages (90d / 30d /
 * 5d / 0d) ... a default template set ships out of the box." Seeds the
 * tenant-wide (document_type_id = NULL) default for every stage so a
 * new tenant's documents are covered immediately without an Admin
 * having to configure anything first.
 */
class ReminderScheduleSeeder extends Seeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenants = $tenant ? collect([$tenant]) : Tenant::all();
        $defaultChannels = array_map(fn ($c) => $c->value, NotificationChannel::cases());

        foreach ($tenants as $t) {
            foreach (NotificationStage::orderedCases() as $stage) {
                ReminderSchedule::firstOrCreate(
                    ['tenant_id' => $t->id, 'document_type_id' => null, 'stage' => $stage->value],
                    [
                        'offset_days' => $stage->defaultOffsetDays(),
                        'channels' => $defaultChannels,
                        'active' => true,
                    ]
                );
            }

            // T23 — FSD 5 design rule: "repeats on a configurable cadence
            // (default: every 7 days)".
            ReminderSchedule::firstOrCreate(
                ['tenant_id' => $t->id, 'document_type_id' => null, 'stage' => NotificationStage::Escalation->value],
                [
                    'offset_days' => 0,
                    'cadence_days' => 7,
                    'channels' => $defaultChannels,
                    'active' => true,
                ]
            );
        }
    }
}
