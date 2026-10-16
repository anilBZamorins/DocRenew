import { HttpClient, HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, switchMap, throwError } from 'rxjs';
import { environment } from '../../environments/environment';

const ACCESS_TOKEN_KEY = 'docrenew_access_token';
const REFRESH_TOKEN_KEY = 'docrenew_refresh_token';

/**
 * Attaches the Sanctum access token to every API request, and on a 401
 * (the access token's 30-min TTL expired mid-session, FSD 3.4) makes one
 * attempt to exchange the refresh token for a new pair via /auth/refresh
 * before retrying the original request. /auth/login and /auth/refresh
 * themselves are left untouched — the access token isn't ready yet, and
 * the refresh call needs the refresh token in its place.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const router = inject(Router);

  if (!req.url.startsWith(environment.apiUrl)) {
    return next(req);
  }

  const isAuthEndpoint = req.url.endsWith('/auth/login') || req.url.endsWith('/auth/refresh');
  const token = isAuthEndpoint && req.url.endsWith('/auth/refresh')
    ? localStorage.getItem(REFRESH_TOKEN_KEY)
    : localStorage.getItem(ACCESS_TOKEN_KEY);

  const authedReq = token ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : req;

  return next(authedReq).pipe(
    catchError((error: unknown) => {
      if (
        error instanceof HttpErrorResponse &&
        error.status === 401 &&
        !isAuthEndpoint
      ) {
        const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY);
        if (!refreshToken) {
          router.navigate(['/login']);
          return throwError(() => error);
        }

        const http = inject(HttpClient);
        return http
          .post<{ access_token: string; refresh_token: string }>(`${environment.apiUrl}/auth/refresh`, {}, {
            headers: { Authorization: `Bearer ${refreshToken}` },
          })
          .pipe(
            switchMap((tokens) => {
              localStorage.setItem(ACCESS_TOKEN_KEY, tokens.access_token);
              localStorage.setItem(REFRESH_TOKEN_KEY, tokens.refresh_token);
              const retried = req.clone({ setHeaders: { Authorization: `Bearer ${tokens.access_token}` } });
              return next(retried);
            }),
            catchError((refreshError) => {
              localStorage.removeItem(ACCESS_TOKEN_KEY);
              localStorage.removeItem(REFRESH_TOKEN_KEY);
              router.navigate(['/login']);
              return throwError(() => refreshError);
            }),
          );
      }

      return throwError(() => error);
    }),
  );
};
