import { Injectable, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { environment } from '../../environments/environment';
import { AuthenticatedUser } from './models';

interface TokenPair {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
}

const ACCESS_TOKEN_KEY = 'docrenew_access_token';
const REFRESH_TOKEN_KEY = 'docrenew_refresh_token';
const TENANT_ID_KEY = 'docrenew_tenant_id';

/**
 * T31 — talks to T19's auth endpoints. Sign-in needs a tenant_id, which
 * the Jira task sheet's FSD doesn't surface a separate "find my
 * workspace" screen for, so the login form collects it directly
 * alongside email/password (matching AuthController::login()'s
 * required fields exactly) rather than inventing a lookup step.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  readonly currentUser = signal<AuthenticatedUser | null>(null);

  constructor(private readonly http: HttpClient, private readonly router: Router) {}

  login(tenantId: string, email: string, password: string): Observable<TokenPair> {
    return this.http
      .post<TokenPair>(`${environment.apiUrl}/auth/login`, { tenant_id: tenantId, email, password })
      .pipe(
        tap((tokens) => {
          localStorage.setItem(ACCESS_TOKEN_KEY, tokens.access_token);
          localStorage.setItem(REFRESH_TOKEN_KEY, tokens.refresh_token);
          localStorage.setItem(TENANT_ID_KEY, tenantId);
        }),
      );
  }

  fetchMe(): Observable<AuthenticatedUser> {
    return this.http.get<AuthenticatedUser>(`${environment.apiUrl}/auth/me`).pipe(
      tap((user) => this.currentUser.set(user)),
    );
  }

  logout(): void {
    const token = this.accessToken;
    localStorage.removeItem(ACCESS_TOKEN_KEY);
    localStorage.removeItem(REFRESH_TOKEN_KEY);
    localStorage.removeItem(TENANT_ID_KEY);
    this.currentUser.set(null);
    if (token) {
      this.http.post(`${environment.apiUrl}/auth/logout`, {}).subscribe({ error: () => {} });
    }
    this.router.navigate(['/login']);
  }

  get accessToken(): string | null {
    return localStorage.getItem(ACCESS_TOKEN_KEY);
  }

  get refreshToken(): string | null {
    return localStorage.getItem(REFRESH_TOKEN_KEY);
  }

  get isAuthenticated(): boolean {
    return !!this.accessToken;
  }

  /** FSD 3.2 "Tenant configuration (Admin full, HR view, Manager/Employee none)" and the T31 dashboard toolbar's own Admin/HR-only reach. */
  get isTenantWide(): boolean {
    const role = this.currentUser()?.role;
    return role === 'ADMIN' || role === 'HR';
  }
}
