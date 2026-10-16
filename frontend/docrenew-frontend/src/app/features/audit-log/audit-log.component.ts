import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AuditLogService } from './audit-log.service';
import { AuditLogRecord } from '../../core/models';

/** Every AuditLog::record() action string currently emitted anywhere in the backend (grepped across app/Http/Controllers). */
const KNOWN_ACTIONS = [
  'document.create',
  'document.update',
  'document.renew',
  'document.escalation.dismissed',
  'document.reassign',
  'document.reminder.manual_send',
  'document.viewNumber',
  'document.delete',
  'document.attachment.rejected',
  'document.attachment.upload',
  'document.attachment.link_issued',
  'document.attachment.download',
  'config.reminder_schedules.update',
  'config.settings.update',
  'report.requested',
  'report.link_issued',
  'report.downloaded',
  'employee_profile.create',
  'employee_profile.update',
  'employee_profile.deactivate',
  'employee_profile.reactivate',
];

const KNOWN_ENTITY_TYPES = ['Document', 'Tenant', 'Report', 'EmployeeProfile'];

/**
 * T36 — Audit log viewer (FSD 3.2 "Audit log | Full | View | None |
 * None"). Admin/HR only — redirects anyone else away in ngOnInit, same
 * pattern as admin-config and reports. Reads AuditLogController::index()
 * (T25), which was fully built but had no frontend caller until now.
 */
@Component({
  selector: 'app-audit-log',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './audit-log.component.html',
  styleUrl: './audit-log.component.css',
})
export class AuditLogComponent implements OnInit {
  private readonly auditLogService = inject(AuditLogService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly knownActions = KNOWN_ACTIONS;
  readonly knownEntityTypes = KNOWN_ENTITY_TYPES;

  readonly logs = signal<AuditLogRecord[]>([]);
  readonly loading = signal(false);
  readonly errorMessage = signal<string | null>(null);
  readonly expandedId = signal<string | null>(null);

  readonly actionFilter = signal('');
  readonly entityTypeFilter = signal('');
  readonly entityIdFilter = signal('');
  readonly dateFrom = signal('');
  readonly dateTo = signal('');

  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);

  ngOnInit(): void {
    if (!this.auth.isTenantWide) {
      this.router.navigate(['/dashboard']);
      return;
    }
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);

    this.auditLogService
      .list({
        action: this.actionFilter() || undefined,
        entity_type: this.entityTypeFilter() || undefined,
        entity_id: this.entityIdFilter() || undefined,
        date_from: this.dateFrom() || undefined,
        date_to: this.dateTo() || undefined,
        page: this.page(),
        per_page: 25,
      })
      .subscribe({
        next: (res) => {
          this.logs.set(res.data);
          this.lastPage.set(res.meta?.last_page ?? 1);
          this.total.set(res.meta?.total ?? res.data.length);
          this.loading.set(false);
        },
        error: () => {
          this.errorMessage.set('Could not load the audit log.');
          this.loading.set(false);
        },
      });
  }

  applyFilters(): void {
    this.page.set(1);
    this.load();
  }

  clearFilters(): void {
    this.actionFilter.set('');
    this.entityTypeFilter.set('');
    this.entityIdFilter.set('');
    this.dateFrom.set('');
    this.dateTo.set('');
    this.page.set(1);
    this.load();
  }

  goToPage(p: number): void {
    if (p < 1 || p > this.lastPage()) return;
    this.page.set(p);
    this.load();
  }

  toggleExpand(id: string): void {
    this.expandedId.set(this.expandedId() === id ? null : id);
  }

  metadataJson(log: AuditLogRecord): string {
    if (!log.metadata || Object.keys(log.metadata).length === 0) return '—';
    return JSON.stringify(log.metadata, null, 2);
  }

  actionLabel(action: string): string {
    return action
      .split('.')
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' › ')
      .replace(/_/g, ' ');
  }
}
