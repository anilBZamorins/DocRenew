import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth.service';
import { EmployeesService } from '../employees.service';
import { EmployeeProfile } from '../../../core/models';

/**
 * T33 — Employee profile list (FSD 3.2/4.4). EmployeeProfile::visibleTo()
 * already scopes WHICH rows come back per role (Admin/HR -> tenant-wide,
 * Manager -> self + direct reports, Employee -> self only), so this
 * component only has to adapt presentation, not re-derive scope:
 *   - Employee always resolves to exactly one row (themselves) -- rather
 *     than render a list of one, we skip straight to their own detail
 *     page, matching the mockup's dedicated "My Profile" screen.
 *   - Manager sees a short "My Team" table with no create/manage actions.
 *   - Admin/HR see the full "Employee Profiles" table with search,
 *     department/status filters, and "+ Add employee".
 */
@Component({
  selector: 'app-employees-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './employees-list.component.html',
  styleUrl: './employees-list.component.css',
})
export class EmployeesListComponent implements OnInit {
  private readonly employeesService = inject(EmployeesService);
  private readonly router = inject(Router);
  readonly auth = inject(AuthService);

  readonly profiles = signal<EmployeeProfile[]>([]);
  readonly loading = signal(true);
  readonly errorMessage = signal<string | null>(null);

  readonly search = signal('');
  readonly departmentFilter = signal('');
  readonly activeFilter = signal<'all' | 'active' | 'inactive'>('active');

  readonly departments = computed(() => {
    const set = new Set<string>();
    for (const p of this.profiles()) if (p.department) set.add(p.department);
    return [...set].sort();
  });

  /** manager_id -> name, derived from the same visible set (a Manager's own manager may fall outside their scope and simply won't resolve). */
  readonly managerNames = computed(() => {
    const map = new Map<string, string>();
    for (const p of this.profiles()) map.set(p.id, p.name);
    return map;
  });

  readonly filteredProfiles = computed(() => {
    const term = this.search().trim().toLowerCase();
    return this.profiles().filter((p) => {
      if (this.departmentFilter() && p.department !== this.departmentFilter()) return false;
      if (this.activeFilter() === 'active' && !p.active) return false;
      if (this.activeFilter() === 'inactive' && p.active) return false;
      if (term) {
        const haystack = `${p.name} ${p.employee_code ?? ''} ${p.contact_email ?? ''}`.toLowerCase();
        if (!haystack.includes(term)) return false;
      }
      return true;
    });
  });

  ngOnInit(): void {
    // An Employee's visible set is always exactly their own profile —
    // send them straight to it instead of showing a one-row table.
    if (!this.auth.isTenantWide && this.auth.currentUser()?.role === 'EMPLOYEE') {
      const ownId = this.auth.currentUser()?.employee_profile?.id;
      if (ownId) {
        this.router.navigate(['/employees', ownId], { replaceUrl: true });
        return;
      }
    }
    this.refresh();
  }

  refresh(): void {
    this.loading.set(true);
    this.errorMessage.set(null);
    this.employeesService.list({}, 1, 200).subscribe({
      next: (res) => {
        this.profiles.set(res.data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.errorMessage.set('Could not load employee profiles.');
      },
    });
  }

  managerName(managerId: string | null): string {
    if (!managerId) return '—';
    return this.managerNames().get(managerId) ?? '—';
  }

  initials(name: string): string {
    return name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('');
  }
}
