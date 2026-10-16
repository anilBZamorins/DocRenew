import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { ReportsService } from './reports.service';
import { DocumentsService } from '../documents/documents.service';
import { DocumentType, ReportFormat, ReportRecord, ReportType } from '../../core/models';

interface ReportTypeOption {
  value: ReportType;
  label: string;
  description: string;
}

const REPORT_TYPES: ReportTypeOption[] = [
  { value: 'COMPLIANCE_SNAPSHOT', label: 'Compliance Snapshot', description: 'Full document inventory with current status, as of today.' },
  { value: 'OVERDUE', label: 'Overdue / Expired', description: 'All documents past expiry, with days overdue and escalation status.' },
  { value: 'NOTIFICATION_ACTIVITY', label: 'Notification Activity', description: 'Delivery log by channel and outcome for a selected date range.' },
  { value: 'RENEWAL_HISTORY', label: 'Renewal History', description: 'All renewal events in a selected period, for audit trail purposes.' },
];

const STATUS_LABEL: Record<ReportRecord['status'], string> = {
  PENDING: 'Queued',
  PROCESSING: 'Generating…',
  COMPLETED: 'Ready',
  FAILED: 'Failed',
};

const STATUS_CLASSES: Record<ReportRecord['status'], string> = {
  PENDING: 'bg-idle-bg text-idle',
  PROCESSING: 'bg-warn-bg text-warn',
  COMPLETED: 'bg-ok-bg text-ok',
  FAILED: 'bg-urgent-bg text-urgent',
};

/**
 * T35 — Reports & compliance export (FSD 4.3, T25's APIs). Requesting a
 * report (POST /reports) can come back already COMPLETED (small
 * reports generate inline, per ReportController::store()'s row-count
 * threshold) or PENDING/PROCESSING (queued) — this component polls
 * GET /reports/:id every few seconds for any row still in flight, and
 * stops once it lands on COMPLETED or FAILED.
 *
 * There's no GET /reports (list) endpoint yet, so "Recently generated"
 * below is only what THIS component has requested since the page was
 * opened — not a durable history. That's called out in the template
 * rather than left implicit, since a list that resets on refresh could
 * otherwise read as a bug.
 */
@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './reports.component.html',
  styleUrl: './reports.component.css',
})
export class ReportsComponent implements OnInit, OnDestroy {
  private readonly reportsService = inject(ReportsService);
  private readonly documentsService = inject(DocumentsService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly reportTypes = REPORT_TYPES;
  readonly statusLabel = STATUS_LABEL;
  readonly statusClasses = STATUS_CLASSES;

  readonly documentTypes = signal<DocumentType[]>([]);
  readonly reports = signal<ReportRecord[]>([]);
  readonly requesting = signal(false);
  readonly errorMessage = signal<string | null>(null);

  readonly selectedType = signal<ReportType>('COMPLIANCE_SNAPSHOT');
  readonly selectedFormat = signal<ReportFormat>('PDF');
  readonly dateFrom = signal('');
  readonly dateTo = signal('');
  readonly documentTypeId = signal('');
  readonly department = signal('');

  private readonly pollTimers = new Map<string, ReturnType<typeof setTimeout>>();

  ngOnInit(): void {
    if (this.auth.currentUser()?.role === 'EMPLOYEE') {
      this.router.navigate(['/dashboard']);
      return;
    }
    this.documentsService.listDocumentTypes().subscribe({
      next: (res) => this.documentTypes.set(res.data),
      error: () => {},
    });
  }

  ngOnDestroy(): void {
    for (const timer of this.pollTimers.values()) clearTimeout(timer);
    this.pollTimers.clear();
  }

  selectedTypeDescription(): string {
    return this.reportTypes.find((t) => t.value === this.selectedType())?.description ?? '';
  }

  typeLabel(type: ReportType): string {
    return this.reportTypes.find((t) => t.value === type)?.label ?? type;
  }

  generate(): void {
    this.requesting.set(true);
    this.errorMessage.set(null);

    const filters: Record<string, string> = {};
    if (this.dateFrom()) filters['date_from'] = this.dateFrom();
    if (this.dateTo()) filters['date_to'] = this.dateTo();
    if (this.documentTypeId()) filters['document_type_id'] = this.documentTypeId();
    if (this.department()) filters['department'] = this.department();

    this.reportsService.request(this.selectedType(), this.selectedFormat(), filters).subscribe({
      next: (res) => {
        this.requesting.set(false);
        this.reports.set([res.data, ...this.reports()]);
        if (res.data.status === 'PENDING' || res.data.status === 'PROCESSING') {
          this.pollReport(res.data.id);
        }
      },
      error: (err) => {
        this.requesting.set(false);
        this.errorMessage.set(
          err?.error?.message || Object.values(err?.error?.errors ?? {})[0]?.[0] || 'Could not request this report.',
        );
      },
    });
  }

  private pollReport(id: string): void {
    const timer = setTimeout(() => {
      this.reportsService.get(id).subscribe({
        next: (res) => {
          this.reports.set(this.reports().map((r) => (r.id === id ? res.data : r)));
          if (res.data.status === 'PENDING' || res.data.status === 'PROCESSING') {
            this.pollReport(id);
          } else {
            this.pollTimers.delete(id);
          }
        },
        error: () => {
          this.pollTimers.delete(id);
        },
      });
    }, 3000);
    this.pollTimers.set(id, timer);
  }

  download(report: ReportRecord): void {
    this.reportsService.getDownloadUrl(report.id).subscribe({
      next: (res) => window.open(res.url, '_blank', 'noopener'),
      error: () => this.errorMessage.set('Could not generate a download link for this report.'),
    });
  }

  documentTypeName(id?: string): string {
    if (!id) return '';
    return this.documentTypes().find((t) => t.id === id)?.name ?? '';
  }
}
