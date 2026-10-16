import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../../core/auth.service';
import { EmployeesService } from '../employees.service';
import { EmployeeProfile } from '../../../core/models';

/**
 * T33 — employee profile view/edit (FSD 3.2/4.4). Mirrors
 * EmployeeProfilePolicy::update() + UpdateEmployeeProfileRequest exactly:
 *   - Admin/HR: every field editable, plus deactivate/reactivate.
 *   - Employee on their OWN profile: contact_email/contact_phone only —
 *     name/department/job title/manager are shown read-only.
 *   - Manager viewing a direct report (or anyone else landing here
 *     without edit rights): fully read-only, no edit affordance at all.
 */
@Component({
  selector: 'app-employee-detail',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './employee-detail.component.html',
  styleUrl: './employee-detail.component.css',
})
export class EmployeeDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly employeesService = inject(EmployeesService);
  readonly auth = inject(AuthService);

  readonly profileId = this.route.snapshot.paramMap.get('id')!;

  readonly profile = signal<EmployeeProfile | null>(null);
  readonly managers = signal<EmployeeProfile[]>([]);
  readonly loading = signal(true);
  readonly errorMessage = signal<string | null>(null);
  readonly actionMessage = signal<string | null>(null);

  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly editName = signal('');
  readonly editEmployeeCode = signal('');
  readonly editDepartment = signal('');
  readonly editJobTitle = signal('');
  readonly editManagerId = signal('');
  readonly editContactEmail = signal('');
  readonly editContactPhone = signal('');

  readonly deactivating = signal(false);
  readonly deactivateConfirmOpen = signal(false);

  readonly isOwnProfile = computed(() => this.profile()?.id === this.auth.currentUser()?.employee_profile?.id);

  /** Admin/HR -> full edit; an Employee on their own profile -> contact fields only; everyone else -> read-only. */
  readonly editScope = computed<'full' | 'contact' | 'none'>(() => {
    if (!this.profile()) return 'none';
    if (this.auth.isTenantWide) return 'full';
    if (this.auth.currentUser()?.role === 'EMPLOYEE' && this.isOwnProfile()) return 'contact';
    return 'none';
  });

  readonly canEdit = computed(() => this.editScope() !== 'none');

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.employeesService.get(this.profileId).subscribe({
      next: (res) => {
        this.profile.set(res.data);
        this.resetEditFields(res.data);
        this.loading.set(false);
        if (this.auth.isTenantWide) {
          this.employeesService.listAll().subscribe({
            next: (list) => this.managers.set(list.data.filter((m) => m.id !== res.data.id)),
            error: () => {},
          });
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.errorMessage.set(
          err?.status === 404 ? 'This employee profile does not exist or you do not have access to it.' : 'Could not load this profile.',
        );
      },
    });
  }

  private resetEditFields(p: EmployeeProfile): void {
    this.editName.set(p.name);
    this.editEmployeeCode.set(p.employee_code ?? '');
    this.editDepartment.set(p.department ?? '');
    this.editJobTitle.set(p.job_title ?? '');
    this.editManagerId.set(p.manager_id ?? '');
    this.editContactEmail.set(p.contact_email ?? '');
    this.editContactPhone.set(p.contact_phone ?? '');
  }

  startEditing(): void {
    const p = this.profile();
    if (p) this.resetEditFields(p);
    this.editing.set(true);
  }

  cancelEditing(): void {
    this.editing.set(false);
  }

  saveEdit(): void {
    this.saving.set(true);
    this.errorMessage.set(null);

    // Only send the fields this role is actually allowed to change —
    // UpdateEmployeeProfileRequest would just drop the rest, but being
    // explicit here keeps the request honest about what's happening.
    const payload =
      this.editScope() === 'full'
        ? {
            name: this.editName().trim(),
            employee_code: this.editEmployeeCode() || null,
            department: this.editDepartment() || null,
            job_title: this.editJobTitle() || null,
            manager_id: this.editManagerId() || null,
            contact_email: this.editContactEmail() || null,
            contact_phone: this.editContactPhone() || null,
          }
        : {
            contact_email: this.editContactEmail() || null,
            contact_phone: this.editContactPhone() || null,
          };

    this.employeesService.update(this.profileId, payload).subscribe({
      next: (res) => {
        this.profile.set(res.data);
        this.saving.set(false);
        this.editing.set(false);
        this.actionMessage.set('Profile updated.');
      },
      error: (err) => {
        this.saving.set(false);
        this.errorMessage.set(
          err?.error?.message || Object.values(err?.error?.errors ?? {})[0]?.[0] || 'Could not save these changes.',
        );
      },
    });
  }

  confirmDeactivate(): void {
    this.deactivating.set(true);
    this.employeesService.deactivate(this.profileId).subscribe({
      next: (res) => {
        this.profile.set(res.data);
        this.deactivating.set(false);
        this.deactivateConfirmOpen.set(false);
        this.actionMessage.set('Employee deactivated. Their user account has been suspended; document history is retained.');
      },
      error: () => {
        this.deactivating.set(false);
        this.deactivateConfirmOpen.set(false);
        this.actionMessage.set('Could not deactivate this employee.');
      },
    });
  }

  reactivate(): void {
    this.deactivating.set(true);
    this.employeesService.reactivate(this.profileId).subscribe({
      next: (res) => {
        this.profile.set(res.data);
        this.deactivating.set(false);
        this.actionMessage.set('Employee reactivated.');
      },
      error: () => {
        this.deactivating.set(false);
        this.actionMessage.set('Could not reactivate this employee.');
      },
    });
  }

  managerName(managerId: string | null): string {
    if (!managerId) return '—';
    return this.managers().find((m) => m.id === managerId)?.name ?? '—';
  }
}
