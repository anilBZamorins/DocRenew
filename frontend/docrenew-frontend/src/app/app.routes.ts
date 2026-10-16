import { Routes } from '@angular/router';
import { authGuard } from './core/auth.guard';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
  {
    path: 'login',
    loadComponent: () => import('./features/login/login.component').then((m) => m.LoginComponent),
  },
  {
    // T37 — every authenticated screen lives under the shared shell
    // (topbar + side nav, core/shell/app-shell.component). authGuard
    // only needs to sit here: a child route can't render without this
    // parent's <router-outlet> activating first, so it covers every
    // child below the same as it covered each one individually before.
    path: '',
    canActivate: [authGuard],
    loadComponent: () => import('./core/shell/app-shell.component').then((m) => m.AppShellComponent),
    children: [
      {
        path: 'dashboard',
        loadComponent: () => import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        path: 'documents/new',
        loadComponent: () =>
          import('./features/documents/document-form/document-form.component').then((m) => m.DocumentFormComponent),
      },
      {
        path: 'documents/:id',
        loadComponent: () =>
          import('./features/documents/document-detail/document-detail.component').then((m) => m.DocumentDetailComponent),
      },
      {
        path: 'employees',
        loadComponent: () =>
          import('./features/employees/employees-list/employees-list.component').then((m) => m.EmployeesListComponent),
      },
      {
        path: 'employees/new',
        loadComponent: () =>
          import('./features/employees/employee-form/employee-form.component').then((m) => m.EmployeeFormComponent),
      },
      {
        path: 'employees/:id',
        loadComponent: () =>
          import('./features/employees/employee-detail/employee-detail.component').then((m) => m.EmployeeDetailComponent),
      },
      {
        path: 'admin/config',
        loadComponent: () =>
          import('./features/admin-config/admin-config.component').then((m) => m.AdminConfigComponent),
      },
      {
        path: 'reports',
        loadComponent: () => import('./features/reports/reports.component').then((m) => m.ReportsComponent),
      },
      {
        path: 'audit-log',
        loadComponent: () => import('./features/audit-log/audit-log.component').then((m) => m.AuditLogComponent),
      },
    ],
  },
  { path: '**', redirectTo: 'dashboard' },
];
