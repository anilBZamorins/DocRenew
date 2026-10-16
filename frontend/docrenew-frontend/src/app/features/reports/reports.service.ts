import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ReportFilters, ReportFormat, ReportRecord, ReportType } from '../../core/models';

/**
 * T35 — Reports & compliance export UI. Talks to T25's ReportController.
 * There is deliberately no list() method here — the backend has no
 * GET /reports (index), only store()/show()/downloadUrl() — so
 * ReportsComponent tracks what it has requested this session itself
 * rather than this service pretending to offer a history it can't.
 */
@Injectable({ providedIn: 'root' })
export class ReportsService {
  constructor(private readonly http: HttpClient) {}

  request(type: ReportType, format: ReportFormat, filters: ReportFilters): Observable<{ data: ReportRecord }> {
    return this.http.post<{ data: ReportRecord }>(`${environment.apiUrl}/reports`, { type, format, filters });
  }

  get(id: string): Observable<{ data: ReportRecord }> {
    return this.http.get<{ data: ReportRecord }>(`${environment.apiUrl}/reports/${id}`);
  }

  getDownloadUrl(id: string): Observable<{ url: string; expires_in: number }> {
    return this.http.get<{ url: string; expires_in: number }>(`${environment.apiUrl}/reports/${id}/download`);
  }
}
