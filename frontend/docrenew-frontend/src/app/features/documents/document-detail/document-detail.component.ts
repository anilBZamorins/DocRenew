import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth.service';
import { DocumentsService } from '../documents.service';
import { DocumentRecord, DocumentStatus, DocumentType } from '../../../core/models';

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

const PILL_TONE_CLASSES: Record<StatusPresentation['tone'], string> = {
  ok: 'bg-ok-bg text-ok',
  warn: 'bg-warn-bg text-warn',
  urgent: 'bg-urgent-bg text-urgent',
  crit: 'bg-crit-bg text-crit',
  idle: 'bg-idle-bg text-idle',
};

/**
 * T32 — document view / edit / attachment screen (FSD 4.1). Mirrors
 * docrenew_mockups.html's "Document detail" panel, but as its own page
 * (route /documents/:id) rather than a side panel, so it can carry the
 * full edit form, the attachment upload/replace/download flow (T21),
 * the masked-number reveal (audit-logged on every call), and the same
 * renew flow the dashboard's quick action uses.
 *
 * Edit/delete/attachment/renew are only offered when the viewer can
 * actually perform them — mirrors DocumentPolicy::canFullyManage()
 * (tenant-wide, or the document's own owner) rather than view()'s
 * wider reach (which also covers a Manager's "view only" direct
 * reports) — attempting these as a read-only viewer would just 403.
 */
@Component({
  selector: 'app-document-detail',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './document-detail.component.html',
  styleUrl: './document-detail.component.css',
})
export class DocumentDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly documentsService = inject(DocumentsService);
  readonly auth = inject(AuthService);

  readonly documentId = this.route.snapshot.paramMap.get('id')!;

  readonly doc = signal<DocumentRecord | null>(null);
  readonly documentTypes = signal<DocumentType[]>([]);
  readonly loading = signal(true);
  readonly errorMessage = signal<string | null>(null);
  readonly actionMessage = signal<string | null>(null);

  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly editDocumentTypeId = signal('');
  readonly editItemLabel = signal('');
  readonly editDocumentNumber = signal('');
  readonly editIssueDate = signal('');
  readonly editExpiryDate = signal('');

  readonly revealedNumber = signal<string | null>(null);
  readonly revealing = signal(false);

  readonly uploading = signal(false);
  readonly downloadingAttachment = signal(false);

  readonly renewOpen = signal(false);
  readonly renewExpiryDate = signal('');
  readonly renewDocumentNumber = signal('');
  readonly renewing = signal(false);

  readonly deleting = signal(false);
  readonly deleteConfirmOpen = signal(false);

  readonly dismissOpen = signal(false);
  readonly dismissReason = signal('');
  readonly dismissing = signal(false);

  /**
   * T36 — DocumentPolicy::dismissEscalation(): Admin/HR (tenant-wide) or
   * a Manager on their own team's document; never an Employee, even on
   * their own. canManage() already matches tenant-wide-or-own-document,
   * which is a superset of what dismiss needs for a Manager (team, not
   * just their own record) — the backend enforces the exact boundary
   * regardless, this just avoids showing a button that would 403.
   */
  readonly canDismissEscalation = computed(() => this.auth.currentUser()?.role !== 'EMPLOYEE' && this.canManage());

  readonly canManage = computed(() => {
    const d = this.doc();
    const user = this.auth.currentUser();
    if (!d || !user) return false;
    if (this.auth.isTenantWide) return true;
    return !!d.employee_profile_id && d.employee_profile_id === user.employee_profile?.id;
  });

  readonly statusPresentation = computed<StatusPresentation>(() => {
    const status = this.doc()?.status;
    return status ? STATUS_PRESENTATION[status] ?? { tone: 'idle', label: status } : { tone: 'idle', label: '' };
  });

  readonly pillClasses = computed(() => PILL_TONE_CLASSES[this.statusPresentation().tone]);

  ngOnInit(): void {
    this.load();
    this.documentsService.listDocumentTypes().subscribe({
      next: (res) => this.documentTypes.set(res.data),
      error: () => {},
    });
  }

  load(): void {
    this.loading.set(true);
    this.documentsService.getDocument(this.documentId).subscribe({
      next: (res) => {
        this.doc.set(res.data);
        this.resetEditFields(res.data);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.errorMessage.set(
          err?.status === 404 ? 'This document does not exist or you do not have access to it.' : 'Could not load this document.',
        );
      },
    });
  }

  private resetEditFields(d: DocumentRecord): void {
    this.editDocumentTypeId.set(d.document_type_id);
    this.editItemLabel.set(d.item_label ?? '');
    this.editDocumentNumber.set('');
    this.editIssueDate.set(d.issue_date ?? '');
    this.editExpiryDate.set(d.expiry_date ?? '');
  }

  startEditing(): void {
    const d = this.doc();
    if (d) this.resetEditFields(d);
    this.editing.set(true);
  }

  cancelEditing(): void {
    this.editing.set(false);
  }

  saveEdit(): void {
    this.saving.set(true);
    this.errorMessage.set(null);

    const payload: Record<string, string | null> = {
      document_type_id: this.editDocumentTypeId(),
      item_label: this.editItemLabel() || null,
      issue_date: this.editIssueDate() || null,
      expiry_date: this.editExpiryDate(),
    };
    // Only send a new document_number if the user actually typed one —
    // an empty field here means "leave it alone," not "clear it."
    if (this.editDocumentNumber()) {
      payload['document_number'] = this.editDocumentNumber();
    }

    this.documentsService.updateDocument(this.documentId, payload as any).subscribe({
      next: (res) => {
        this.doc.set(res.data);
        this.revealedNumber.set(null);
        this.saving.set(false);
        this.editing.set(false);
        this.actionMessage.set('Document updated.');
      },
      error: (err) => {
        this.saving.set(false);
        this.errorMessage.set(err?.error?.message || 'Could not save these changes.');
      },
    });
  }

  toggleReveal(): void {
    if (this.revealedNumber()) {
      this.revealedNumber.set(null);
      return;
    }
    this.revealing.set(true);
    this.documentsService.revealNumber(this.documentId).subscribe({
      next: (res) => {
        this.revealedNumber.set(res.document_number);
        this.revealing.set(false);
      },
      error: () => {
        this.revealing.set(false);
        this.actionMessage.set('Could not reveal the document number.');
      },
    });
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    this.uploading.set(true);
    this.errorMessage.set(null);

    this.documentsService.uploadAttachment(this.documentId, file).subscribe({
      next: (res) => {
        this.doc.set(res.data);
        this.uploading.set(false);
        this.actionMessage.set('Attachment uploaded.');
        input.value = '';
      },
      error: (err) => {
        this.uploading.set(false);
        this.errorMessage.set(
          err?.error?.errors?.file?.[0] || err?.error?.message || 'Could not upload this file.',
        );
        input.value = '';
      },
    });
  }

  downloadAttachment(): void {
    this.downloadingAttachment.set(true);
    this.documentsService.getAttachmentDownloadUrl(this.documentId).subscribe({
      next: (res) => {
        this.downloadingAttachment.set(false);
        window.open(res.url, '_blank', 'noopener');
      },
      error: () => {
        this.downloadingAttachment.set(false);
        this.actionMessage.set('Could not generate a download link.');
      },
    });
  }

  openRenew(): void {
    this.renewExpiryDate.set('');
    this.renewDocumentNumber.set('');
    this.renewOpen.set(true);
  }

  closeRenew(): void {
    this.renewOpen.set(false);
  }

  confirmRenew(): void {
    if (!this.renewExpiryDate()) return;
    this.renewing.set(true);
    this.documentsService.renewDocument(this.documentId, this.renewExpiryDate(), this.renewDocumentNumber() || undefined).subscribe({
      next: (res) => {
        this.doc.set(res.data);
        this.revealedNumber.set(null);
        this.renewing.set(false);
        this.renewOpen.set(false);
        this.actionMessage.set('Document renewed.');
      },
      error: (err) => {
        this.renewing.set(false);
        this.actionMessage.set(err?.error?.message || 'Could not renew this document.');
      },
    });
  }

  openDismiss(): void {
    this.dismissReason.set('');
    this.dismissOpen.set(true);
  }

  closeDismiss(): void {
    this.dismissOpen.set(false);
  }

  confirmDismiss(): void {
    const reason = this.dismissReason().trim();
    if (!reason) return;
    this.dismissing.set(true);
    this.documentsService.dismissEscalation(this.documentId, reason).subscribe({
      next: (res) => {
        this.doc.set(res.data);
        this.dismissing.set(false);
        this.dismissOpen.set(false);
        this.actionMessage.set('Escalation dismissed.');
      },
      error: (err) => {
        this.dismissing.set(false);
        this.actionMessage.set(err?.error?.message || 'Could not dismiss this escalation.');
      },
    });
  }

  confirmDelete(): void {
    this.deleting.set(true);
    this.documentsService.deleteDocument(this.documentId).subscribe({
      next: () => {
        this.router.navigate(['/dashboard']);
      },
      error: () => {
        this.deleting.set(false);
        this.deleteConfirmOpen.set(false);
        this.actionMessage.set('Could not delete this document.');
      },
    });
  }

  formatBytes(bytes: number | null | undefined): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
}
