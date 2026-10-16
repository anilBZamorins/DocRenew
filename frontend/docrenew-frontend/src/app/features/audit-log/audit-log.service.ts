import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { AuditLogFilters, AuditLogRecord, PaginatedResponse } from '../../core/models';

/**
 * T36 — Audit log viewer (FSD 3.2). Hits AuditLogController::index(),
 * already built (T25) with zero frontend usage until now.
 * AuditLogPolicy::viewAny() is Admin/HR only — the server 403s anyone
 * else, so the component's own role check (gating the route/nav link)
 * is just to avoid showing a page that would immediately error.
 */
@Injectable({ providedIn: 'root' })
export class AuditLogService {
  constructor(private readonly http: HttpClient) {}

  list(filters: AuditLogFilters = {}): Observable<PaginatedResponse<AuditLogRecord>> {
    let params = new HttpParams();
    if (filters.action) params = params.set('action', filters.action);
    if (filters.entity_type) params = params.set('entity_type', filters.entity_type);
    if (filters.entity_id) params = params.set('entity_id', filters.entity_id);
    if (filters.acting_user_id) params = params.set('acting_user_id', filters.acting_user_id);
    if (filters.date_from) params = params.set('date_from', filters.date_from);
    if (filters.date_to) params = params.set('date_to', filters.date_to);
    params = params.set('per_page', filters.per_page ?? 25).set('page', filters.page ?? 1);

    return this.http.get<PaginatedResponse<AuditLogRecord>>(`${environment.apiUrl}/audit-logs`, { params });
  }
}
