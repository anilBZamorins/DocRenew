import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DashboardSummary, DocumentFilters, DocumentRecord, EmployeeProfile, PaginatedResponse } from '../../core/models';

@Injectable({ providedIn: 'root' })
export class DashboardService {
  constructor(private readonly http: HttpClient) {}

  getSummary(): Observable<{ data: DashboardSummary }> {
    return this.http.get<{ data: DashboardSummary }>(`${environment.apiUrl}/dashboard/summary`);
  }

  getDocuments(filters: DocumentFilters, page = 1, perPage = 50): Observable<PaginatedResponse<DocumentRecord>> {
    let params = new HttpParams().set('page', page).set('per_page', perPage);

    if (filters.status) params = params.set('status', filters.status);
    if (filters.document_type_id) params = params.set('document_type_id', filters.document_type_id);
    if (filters.employee_profile_id) params = params.set('employee_profile_id', filters.employee_profile_id);
    // DocumentController::index() has no free-text search filter, so
    // "search" is applied client-side over the loaded page instead.

    return this.http.get<PaginatedResponse<DocumentRecord>>(`${environment.apiUrl}/documents`, { params });
  }

  getEmployeeProfiles(): Observable<{ data: EmployeeProfile[] }> {
    return this.http.get<{ data: EmployeeProfile[] }>(`${environment.apiUrl}/employee-profiles`, {
      params: new HttpParams().set('per_page', 200),
    });
  }

  reassignOwner(documentId: string, employeeProfileId: string): Observable<{ data: DocumentRecord }> {
    return this.http.post<{ data: DocumentRecord }>(`${environment.apiUrl}/documents/${documentId}/reassign`, {
      employee_profile_id: employeeProfileId,
    });
  }

  sendManualReminder(documentId: string): Observable<{ data: { notifications_dispatched: number } }> {
    return this.http.post<{ data: { notifications_dispatched: number } }>(
      `${environment.apiUrl}/documents/${documentId}/send-reminder`,
      {},
    );
  }

  renewDocument(documentId: string, expiryDate: string, documentNumber?: string): Observable<{ data: DocumentRecord }> {
    const payload: Record<string, string> = { expiry_date: expiryDate };
    if (documentNumber) payload['document_number'] = documentNumber;
    return this.http.post<{ data: DocumentRecord }>(`${environment.apiUrl}/documents/${documentId}/renew`, payload);
  }
}
