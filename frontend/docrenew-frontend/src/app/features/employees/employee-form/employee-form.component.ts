import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth.service';
import { EmployeesService } from '../employees.service';
import { EmployeeProfile } from '../../../core/models';

/**
 * T33 — "+ Add employee" (FSD 4.4). EmployeeProfilePolicy::create() is
 * Admin/HR only; the authGuard-gated route still gets an extra belt-
 * and-suspenders redirect here in case a non-tenant-wide user lands on
 * it directly (e.g. a stale link), since the route itself can't express
 * a role check the way authGuard expresses authentication.
 */
@Component({
  selector: 'app-employee-form',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './employee-form.component.html',
  styleUrl: './employee-form.component.css',
})
export class EmployeeFormComponent implements OnInit {
  private readonly employeesService = inject(EmployeesService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly managers = signal<EmployeeProfile[]>([]);
  readonly submitting = signal(false);
  readonly errorMessage = signal<string | null>(null);

  readonly name = signal('');
  readonly employeeCode = signal('');
  readonly department = signal('');
  readonly jobTitle = signal('');
  readonly managerId = signal('');
  readonly contactEmail = signal('');
  readonly contactPhone = signal('');

  ngOnInit(): void {
    if (!this.auth.isTenantWide) {
      this.router.navigate(['/employees']);
      return;
    }
    this.employeesService.listAll().subscribe({
      next: (res) => this.managers.set(res.data),
      error: () => {},
    });
  }

  submit(): void {
    if (!this.name().trim()) {
      this.errorMessage.set('Name is required.');
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);

    this.employeesService
      .create({
        name: this.name().trim(),
        employee_code: this.employeeCode() || null,
        department: this.department() || null,
        job_title: this.jobTitle() || null,
        manager_id: this.managerId() || null,
        contact_email: this.contactEmail() || null,
        contact_phone: this.contactPhone() || null,
      })
      .subscribe({
        next: (res) => {
          this.submitting.set(false);
          this.router.navigate(['/employees', res.data.id]);
        },
        error: (err) => {
          this.submitting.set(false);
          this.errorMessage.set(
            err?.error?.message ||
              Object.values(err?.error?.errors ?? {})[0]?.[0] ||
              'Could not create this employee profile.',
          );
        },
      });
  }
}
