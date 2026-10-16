import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth.service';
import { DocumentsService } from '../documents.service';
import { DocumentCategory, DocumentType, EmployeeProfile } from '../../../core/models';

/**
 * T32 — "+ Add a document" (FSD 4.1). DocumentPolicy::create() lets
 * Admin/HR create for anyone, or an org-owned EXTENDED_ITEM with no
 * owner at all; Manager/Employee can only ever create against their
 * OWN employee_profile_id (StoreDocumentRequest enforces this
 * server-side too), so for them the owner is fixed, not a picker.
 */
@Component({
  selector: 'app-document-form',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './document-form.component.html',
  styleUrl: './document-form.component.css',
})
export class DocumentFormComponent implements OnInit {
  private readonly documentsService = inject(DocumentsService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly documentTypes = signal<DocumentType[]>([]);
  readonly employeeProfiles = signal<EmployeeProfile[]>([]);
  readonly submitting = signal(false);
  readonly errorMessage = signal<string | null>(null);

  readonly category = signal<DocumentCategory>('EMPLOYEE_DOCUMENT');
  readonly ownerId = signal<string>('');
  readonly documentTypeId = signal<string>('');
  readonly itemLabel = signal<string>('');
  readonly documentNumber = signal<string>('');
  readonly issueDate = signal<string>('');
  readonly expiryDate = signal<string>('');

  readonly ownOwnerId = computed(() => this.auth.currentUser()?.employee_profile?.id ?? '');

  readonly filteredDocumentTypes = computed(() =>
    this.documentTypes().filter((t) => t.category === this.category()),
  );

  /** EMPLOYEE_DOCUMENT always needs an owner; EXTENDED_ITEM needs one OR an item label (StoreDocumentRequest's own business rule, mirrored here so the form never submits something the API will 422 on). */
  readonly needsItemLabel = computed(() => this.category() === 'EXTENDED_ITEM' && !this.effectiveOwnerId());

  readonly effectiveOwnerId = computed(() => (this.auth.isTenantWide ? this.ownerId() : this.ownOwnerId()));

  ngOnInit(): void {
    this.documentsService.listDocumentTypes().subscribe({
      next: (res) => {
        this.documentTypes.set(res.data);
        const firstMatch = res.data.find((t) => t.category === this.category());
        if (firstMatch) this.documentTypeId.set(firstMatch.id);
      },
      error: () => this.errorMessage.set('Could not load document types.'),
    });

    if (this.auth.isTenantWide) {
      this.documentsService.listEmployeeProfiles().subscribe({
        next: (res) => this.employeeProfiles.set(res.data),
        error: () => {},
      });
    }
  }

  onCategoryChange(value: DocumentCategory): void {
    this.category.set(value);
    const firstMatch = this.documentTypes().find((t) => t.category === value);
    this.documentTypeId.set(firstMatch?.id ?? '');
  }

  submit(): void {
    if (!this.documentTypeId() || !this.expiryDate()) {
      this.errorMessage.set('Document type and expiry date are required.');
      return;
    }
    if (this.needsItemLabel() && !this.itemLabel().trim()) {
      this.errorMessage.set('An item with no owning employee needs a label (e.g. "Office Lease Renewal").');
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);

    this.documentsService
      .createDocument({
        employee_profile_id: this.effectiveOwnerId() || null,
        document_type_id: this.documentTypeId(),
        category: this.category(),
        item_label: this.itemLabel() || null,
        document_number: this.documentNumber() || null,
        issue_date: this.issueDate() || null,
        expiry_date: this.expiryDate(),
      })
      .subscribe({
        next: (res) => {
          this.submitting.set(false);
          this.router.navigate(['/documents', res.data.id]);
        },
        error: (err) => {
          this.submitting.set(false);
          this.errorMessage.set(
            err?.error?.message ||
              Object.values(err?.error?.errors ?? {})[0]?.[0] ||
              'Could not create this document.',
          );
        },
      });
  }
}
