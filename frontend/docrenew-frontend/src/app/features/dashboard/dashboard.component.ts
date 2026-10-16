import { Component, OnInit, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { DashboardService } from './dashboard.service';
import { DocumentsService } from '../documents/documents.service';
import { DashboardSummary, DocumentFilters, DocumentRecord, DocumentStatus, EmployeeProfile } from '../../core/models';

interface StatusPresentation {
  tone: 'ok' | 'warn' | 'urgent' | 'crit' | 'idle';
  label: string;
}

const STATUS_PRESENTATION: Record<DocumentStatus, StatusPresentation> = {
  VALID: { tone: 'ok', label: 'Valid' },
  EXPIRING_SOON: { tone: 'warn', label: 'Expiring soon' },
  EXPIRING_URGENT: { tone: 'crit', label: 'Expiring urgent' },
  EXPIRED: { tone: 'urgent', label: 'Expired' },
  RENEWED: { tone: 'idle', label: 'Renewed' },
};

/**
 * Written out as full, literal Tailwind class strings (rather than built
 * up from the tone name at runtime) so Tailwind's content scanner, which
 * greps this file's raw text rather than evaluating it, can see every
 * class it needs to generate.
 */
const PILL_TONE_CLASSES: Record<StatusPresentation['tone'], string> = {
  ok: 'bg-ok-bg text-ok',
  warn: 'bg-warn-bg text-warn',
  urgent: 'bg-urgent-bg text-urgent',
  crit: 'bg-crit-bg text-crit',
  idle: 'bg-idle-bg text-idle',
};

const TILE_NUMBER_TONE_CLASSES: Record<StatusPresentation['tone'], string> = {
  ok: 'text-ok',
  warn: 'text-warn',
  urgent: 'text-urgent',
  crit: 'text-crit',
  idle: 'text-navy',
};

/**
 * T31 / FSD 4.3 "Live compliance dashboard UI": five summary tiles, a
 * filterable/sortable document list, and — Admin/HR only, per the
 * HR-vs-Manager mockup distinction in docrenew_mockups.html — bulk
 * actions (export selected, send a manual reminder, reassign owner).
 * Manager/Employee get the same tiles + table with no toolbar and no
 * bulk actions, matching the read-only Team/My Documents mockups.
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.css',
})
export class DashboardComponent implements OnInit {
  readonly summary = signal<DashboardSummary | null>(null);
  readonly documents = signal<DocumentRecord[]>([]);
  readonly employeeProfiles = signal<EmployeeProfile[]>([]);
  readonly loading = signal(false);
  readonly errorMessage = signal<string | null>(null);
  readonly actionMessage = signal<string | null>(null);

  readonly selectedIds = signal<Set<string>>(new Set());
  readonly statusFilter = signal<DocumentStatus | ''>('');
  readonly typeFilter = signal<string>('');
  readonly search = signal<string>('');

  readonly reassignTargetDocId = signal<string | null>(null);
  readonly reassignEmployeeId = signal<string>('');
  readonly renewDocId = signal<string | null>(null);
  readonly renewExpiryDate = signal<string>('');
  readonly renewDocumentNumber = signal<string>('');

  readonly documentTypes = computed(() => {
    const seen = new Map<string, string>();
    for (const doc of this.documents()) {
      if (doc.document_type_id && doc.document_type) {
        seen.set(doc.document_type_id, doc.document_type);
      }
    }
    return Array.from(seen.entries()).map(([id, name]) => ({ id, name }));
  });

  /**
   * Resolves employee_name from whichever of the two in-flight requests
   * (documents, employee-profiles) finishes last, instead of baking the
   * name in at fetch time — baking it in raced the two requests and
   * silently left every row showing "—" whenever the profiles list
   * arrived after the documents page did.
   */
  readonly hydratedDocuments = computed(() => {
    const profileById = new Map(this.employeeProfiles().map((p) => [p.id, p.name]));
    return this.documents().map((d) => ({
      ...d,
      employee_name: d.employee_profile_id ? profileById.get(d.employee_profile_id) ?? '—' : '—',
    }));
  });

  readonly filteredDocuments = computed(() => {
    const term = this.search().trim().toLowerCase();
    return this.hydratedDocuments().filter((doc) => {
      if (!term) return true;
      const haystack = `${doc.employee_name ?? ''} ${doc.document_type ?? ''} ${doc.item_label ?? ''}`.toLowerCase();
      return haystack.includes(term);
    });
  });

  readonly allVisibleSelected = computed(() => {
    const visible = this.filteredDocuments();
    return visible.length > 0 && visible.every((d) => this.selectedIds().has(d.id));
  });

  constructor(
    readonly auth: AuthService,
    private readonly dashboardService: DashboardService,
    private readonly documentsService: DocumentsService,
  ) {}

  ngOnInit(): void {
    this.refresh();
    // Fetched for every role, not just Admin/HR — Manager and Employee
    // need it too, just to resolve names for their own narrower set of
    // visible documents (EmployeeProfileController::index() scopes the
    // list by role on its own, same as the documents endpoint does).
    this.dashboardService.getEmployeeProfiles().subscribe({
      next: (res) => this.employeeProfiles.set(res.data.filter((p) => p.active)),
      error: () => {},
    });
  }

  refresh(): void {
    this.loading.set(true);
    this.errorMessage.set(null);

    this.dashboardService.getSummary().subscribe({
      next: (res) => this.summary.set(res.data),
      error: () => this.errorMessage.set('Could not load the summary tiles.'),
    });

    const filters: DocumentFilters = { status: this.statusFilter(), document_type_id: this.typeFilter() };

    this.dashboardService.getDocuments(filters).subscribe({
      next: (res) => {
        this.documents.set(res.data);
        this.selectedIds.set(new Set());
        this.loading.set(false);
      },
      error: () => {
        this.errorMessage.set('Could not load the document list.');
        this.loading.set(false);
      },
    });
  }

  statusPresentation(status: DocumentStatus): StatusPresentation {
    return STATUS_PRESENTATION[status] ?? { tone: 'idle', label: status };
  }

  pillClasses(status: DocumentStatus): string {
    return PILL_TONE_CLASSES[this.statusPresentation(status).tone];
  }

  tileNumberClasses(tone: StatusPresentation['tone']): string {
    return TILE_NUMBER_TONE_CLASSES[tone];
  }

  initials(name: string | undefined): string {
    if (!name || name === '—') return 'OR';
    return name
      .split(' ')
      .map((p) => p[0])
      .join('')
      .slice(0, 2)
      .toUpperCase();
  }

  onFilterChange(): void {
    this.refresh();
  }

  toggleSelectAll(): void {
    const visible = this.filteredDocuments();
    if (this.allVisibleSelected()) {
      this.selectedIds.set(new Set());
    } else {
      this.selectedIds.set(new Set(visible.map((d) => d.id)));
    }
  }

  toggleSelect(id: string): void {
    const next = new Set(this.selectedIds());
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
    }
    this.selectedIds.set(next);
  }

  isSelected(id: string): boolean {
    return this.selectedIds().has(id);
  }

  get selectedCount(): number {
    return this.selectedIds().size;
  }

  exportSelected(): void {
    const selected = this.documents().filter((d) => this.selectedIds().has(d.id));
    const rows = [
      ['Employee', 'Document Type', 'Expiry Date', 'Status'],
      ...selected.map((d) => [d.employee_name ?? '—', d.document_type ?? '', d.expiry_date ?? '', d.status]),
    ];
    const csv = rows.map((r) => r.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `docrenew_export_${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
    this.actionMessage.set(`Exported ${selected.length} document(s).`);
  }

  sendReminderToSelected(): void {
    const ids = Array.from(this.selectedIds());
    if (ids.length === 0) return;

    this.actionMessage.set(null);
    let dispatched = 0;
    let completed = 0;

    ids.forEach((id) => {
      this.dashboardService.sendManualReminder(id).subscribe({
        next: (res) => {
          dispatched += res.data.notifications_dispatched;
          completed += 1;
          if (completed === ids.length) {
            this.actionMessage.set(`Sent ${dispatched} notification(s) across ${ids.length} document(s).`);
          }
        },
        error: () => {
          completed += 1;
          if (completed === ids.length) {
            this.actionMessage.set(`Sent ${dispatched} notification(s) — some documents could not be reminded.`);
          }
        },
      });
    });
  }

  openReassignModal(): void {
    if (this.selectedCount === 0) return;
    this.reassignTargetDocId.set('bulk');
    this.reassignEmployeeId.set('');
  }

  closeReassignModal(): void {
    this.reassignTargetDocId.set(null);
  }

  confirmReassign(): void {
    const employeeId = this.reassignEmployeeId();
    if (!employeeId) return;

    const ids = Array.from(this.selectedIds());
    let completed = 0;

    ids.forEach((id) => {
      this.dashboardService.reassignOwner(id, employeeId).subscribe({
        next: () => {
          completed += 1;
          if (completed === ids.length) {
            this.actionMessage.set(`Reassigned ${ids.length} document(s).`);
            this.closeReassignModal();
            this.refresh();
          }
        },
        error: () => {
          completed += 1;
          if (completed === ids.length) {
            this.actionMessage.set('Reassignment failed for one or more documents.');
            this.closeReassignModal();
            this.refresh();
          }
        },
      });
    });
  }

  openRenewModal(doc: DocumentRecord): void {
    this.renewDocId.set(doc.id);
    this.renewExpiryDate.set('');
    this.renewDocumentNumber.set('');
  }

  closeRenewModal(): void {
    this.renewDocId.set(null);
  }

  confirmRenew(): void {
    const docId = this.renewDocId();
    const expiry = this.renewExpiryDate();
    if (!docId || !expiry) return;

    this.documentsService.renewDocument(docId, expiry, this.renewDocumentNumber() || undefined).subscribe({
      next: () => {
        this.actionMessage.set('Document renewed.');
        this.closeRenewModal();
        this.refresh();
      },
      error: (err) => {
        this.actionMessage.set(err?.error?.message || 'Could not renew this document.');
      },
    });
  }
}
