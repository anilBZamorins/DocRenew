import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../auth.service';

/**
 * T37 — shared app shell (topbar + side nav), replacing the sticky
 * top-bar that every feature page (dashboard, reports, admin-config,
 * employees, document-detail, audit-log) previously hand-duplicated in
 * its own template. Matches docrenew_mockups.html's intended layout
 * (.topbar + .shell > .sidebar/.main), which the original page-by-page
 * build had drifted away from — one shared place for navigation now,
 * rather than six+ copies that each had to be edited in lockstep
 * whenever a nav link changed (exactly what happened when T36 added the
 * Audit log link only to dashboard.component.html and nowhere else).
 *
 * Every authenticated route is a child of this shell (see
 * app.routes.ts) — authGuard only needs to sit on the shell's own route
 * entry, since a child can't render without the parent outlet first.
 */
@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './app-shell.component.html',
  styleUrl: './app-shell.component.css',
})
export class AppShellComponent {
  readonly auth = inject(AuthService);

  get employeesNavLabel(): string {
    if (this.auth.isTenantWide) return 'Employees';
    return this.auth.currentUser()?.role === 'EMPLOYEE' ? 'My profile' : 'My team';
  }

  initials(name: string | undefined): string {
    if (!name) return '—';
    return name
      .split(' ')
      .map((p) => p[0])
      .join('')
      .slice(0, 2)
      .toUpperCase();
  }
}
