import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AdminConfigService } from './admin-config.service';
import { DocumentsService } from '../documents/documents.service';
import { DocumentType, NotificationChannel, ReminderScheduleRow, ReminderStage, TenantSettings } from '../../core/models';

type Tab = 'reminders' | 'settings' | 'roles';

const STAGE_LABELS: Record<ReminderStage, string> = {
  THREE_MONTH: 'Early reminder — 90 days before',
  ONE_MONTH: 'Follow-up — 30 days before',
  FIVE_DAY: 'Urgent — 5 days before',
  ON_EXPIRY: 'Expiry — on the expiry date',
  ESCALATION: 'Escalation — repeating, after expiry',
};

const STAGE_DEFAULT_OFFSET: Record<ReminderStage, number> = {
  THREE_MONTH: 90,
  ONE_MONTH: 30,
  FIVE_DAY: 5,
  ON_EXPIRY: 0,
  ESCALATION: 0,
};

const DEFAULT_STAGES: ReminderStage[] = ['THREE_MONTH', 'ONE_MONTH', 'FIVE_DAY', 'ON_EXPIRY', 'ESCALATION'];

const ALL_CHANNELS: NotificationChannel[] = ['EMAIL', 'SMS', 'PUSH'];

/**
 * T34 — Admin/config UI (FSD 4.5, T26's APIs). Three read-or-edit
 * sections in one page, each gated by the same Admin-edit / HR-view-only
 * split the backend policies already draw (ReminderSchedulePolicy /
 * TenantPolicy: Admin "Full", HR "View", Manager/Employee "None"):
 *   - Reminder Rules: the 4 one-shot stages + the recurring escalation
 *     stage, tenant-wide, plus any per-document-type overrides.
 *   - Organization Settings: branding, timezone, date format, scan
 *     window.
 *   - Roles & Permissions: a READ-ONLY reference rendering of the FSD
 *     3.2 permission matrix. There's no UserController yet to invite
 *     teammates or change a user's role — "roles" in this task's scope
 *     is informational until that's built, not functional admin.
 * Manager/Employee have no backend access at all to either config
 * endpoint, so they're redirected away in ngOnInit rather than shown a
 * page that would just 403 on load.
 */
@Component({
  selector: 'app-admin-config',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './admin-config.component.html',
  styleUrl: './admin-config.component.css',
})
export class AdminConfigComponent implements OnInit {
  private readonly adminConfigService = inject(AdminConfigService);
  private readonly documentsService = inject(DocumentsService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly tab = signal<Tab>('reminders');
  readonly stageLabels = STAGE_LABELS;
  readonly allChannels = ALL_CHANNELS;

  // ---- Reminder rules ----
  readonly loadingSchedules = signal(true);
  readonly schedulesError = signal<string | null>(null);
  readonly schedulesMessage = signal<string | null>(null);
  readonly savingSchedules = signal(false);
  readonly defaultRows = signal<ReminderScheduleRow[]>([]);
  readonly overrideRows = signal<ReminderScheduleRow[]>([]);
  readonly documentTypes = signal<DocumentType[]>([]);

  readonly newOverrideTypeId = signal('');
  readonly newOverrideStage = signal<ReminderStage>('THREE_MONTH');

  // ---- Organization settings ----
  readonly loadingSettings = signal(true);
  readonly settingsError = signal<string | null>(null);
  readonly settingsMessage = signal<string | null>(null);
  readonly savingSettings = signal(false);
  readonly settingsName = signal('');
  readonly settingsTimezone = signal('');
  readonly settingsLogoUrl = signal('');
  readonly settingsDateFormat = signal<TenantSettings['date_format']>('Y-m-d');
  readonly settingsScanInterval = signal(15);
  readonly settingsScanWindowStart = signal('');
  readonly settingsScanWindowEnd = signal('');

  readonly canEdit = computed(() => this.auth.currentUser()?.role === 'ADMIN');
  readonly isHr = computed(() => this.auth.currentUser()?.role === 'HR');

  ngOnInit(): void {
    if (!this.auth.isTenantWide) {
      this.router.navigate(['/dashboard']);
      return;
    }
    this.loadSchedules();
    this.loadSettings();
    this.documentsService.listDocumentTypes().subscribe({
      next: (res) => this.documentTypes.set(res.data.filter((t) => !t.retired)),
      error: () => {},
    });
  }

  // ---- Reminder rules ----

  loadSchedules(): void {
    this.loadingSchedules.set(true);
    this.schedulesError.set(null);
    this.adminConfigService.getReminderSchedules().subscribe({
      next: (res) => {
        const rows = res.data;
        const defaults: ReminderScheduleRow[] = DEFAULT_STAGES.map((stage) => {
          const existing = rows.find((r) => r.document_type_id === null && r.stage === stage);
          return (
            existing ?? {
              document_type_id: null,
              stage,
              offset_days: STAGE_DEFAULT_OFFSET[stage],
              cadence_days: stage === 'ESCALATION' ? 7 : null,
              channels: ['EMAIL'],
              active: true,
            }
          );
        });
        this.defaultRows.set(defaults);
        this.overrideRows.set(rows.filter((r) => r.document_type_id !== null));
        this.loadingSchedules.set(false);
      },
      error: () => {
        this.loadingSchedules.set(false);
        this.schedulesError.set('Could not load reminder rules.');
      },
    });
  }

  toggleChannel(row: ReminderScheduleRow, channel: NotificationChannel): void {
    if (!this.canEdit()) return;
    const idx = row.channels.indexOf(channel);
    const channels = [...row.channels];
    if (idx === -1) channels.push(channel);
    else channels.splice(idx, 1);
    row.channels = channels;
  }

  addOverride(): void {
    if (!this.newOverrideTypeId()) return;
    const typeId = this.newOverrideTypeId();
    const stage = this.newOverrideStage();
    const alreadyExists =
      this.overrideRows().some((r) => r.document_type_id === typeId && r.stage === stage) ||
      // also block duplicating a row that's about to be added twice before saving
      false;
    if (alreadyExists) {
      this.schedulesError.set('An override for that document type and stage already exists — edit it below instead.');
      return;
    }
    const typeName = this.documentTypes().find((t) => t.id === typeId)?.name ?? null;
    this.overrideRows.set([
      ...this.overrideRows(),
      {
        document_type_id: typeId,
        document_type_name: typeName,
        stage,
        offset_days: STAGE_DEFAULT_OFFSET[stage],
        cadence_days: stage === 'ESCALATION' ? 7 : null,
        channels: ['EMAIL'],
        active: true,
      },
    ]);
    this.newOverrideTypeId.set('');
  }

  /** No destroy endpoint exists for a schedule row (FSD: config rows are deactivated, not deleted) — an unsaved draft can just be dropped locally, a saved row is deactivated instead. */
  removeOverride(row: ReminderScheduleRow): void {
    if (!row.id) {
      this.overrideRows.set(this.overrideRows().filter((r) => r !== row));
      return;
    }
    row.active = false;
  }

  saveSchedules(): void {
    this.savingSchedules.set(true);
    this.schedulesError.set(null);
    this.schedulesMessage.set(null);

    const allRows = [...this.defaultRows(), ...this.overrideRows()];
    const invalid = allRows.find((r) => r.stage === 'ESCALATION' && (!r.cadence_days || r.cadence_days < 1));
    if (invalid) {
      this.savingSchedules.set(false);
      this.schedulesError.set('The escalation stage needs a repeat cadence of at least 1 day.');
      return;
    }

    this.adminConfigService.updateReminderSchedules(allRows).subscribe({
      next: () => {
        this.savingSchedules.set(false);
        this.schedulesMessage.set('Reminder rules saved.');
        this.loadSchedules();
      },
      error: (err) => {
        this.savingSchedules.set(false);
        this.schedulesError.set(
          err?.error?.message || Object.values(err?.error?.errors ?? {})[0]?.[0] || 'Could not save these rules.',
        );
      },
    });
  }

  // ---- Organization settings ----

  loadSettings(): void {
    this.loadingSettings.set(true);
    this.settingsError.set(null);
    this.adminConfigService.getSettings().subscribe({
      next: (res) => {
        const s = res.data;
        this.settingsName.set(s.name);
        this.settingsTimezone.set(s.timezone);
        this.settingsLogoUrl.set(s.logo_url ?? '');
        this.settingsDateFormat.set(s.date_format);
        this.settingsScanInterval.set(s.default_scan_interval_minutes);
        this.settingsScanWindowStart.set(s.default_scan_window_start ?? '');
        this.settingsScanWindowEnd.set(s.default_scan_window_end ?? '');
        this.loadingSettings.set(false);
      },
      error: () => {
        this.loadingSettings.set(false);
        this.settingsError.set('Could not load organization settings.');
      },
    });
  }

  saveSettings(): void {
    this.savingSettings.set(true);
    this.settingsError.set(null);
    this.settingsMessage.set(null);

    this.adminConfigService
      .updateSettings({
        name: this.settingsName().trim(),
        timezone: this.settingsTimezone().trim(),
        logo_url: this.settingsLogoUrl() || null,
        date_format: this.settingsDateFormat(),
        default_scan_interval_minutes: this.settingsScanInterval(),
        default_scan_window_start: this.settingsScanWindowStart() || null,
        default_scan_window_end: this.settingsScanWindowEnd() || null,
      })
      .subscribe({
        next: () => {
          this.savingSettings.set(false);
          this.settingsMessage.set('Organization settings saved.');
        },
        error: (err) => {
          this.savingSettings.set(false);
          this.settingsError.set(
            err?.error?.message || Object.values(err?.error?.errors ?? {})[0]?.[0] || 'Could not save these settings.',
          );
        },
      });
  }
}
