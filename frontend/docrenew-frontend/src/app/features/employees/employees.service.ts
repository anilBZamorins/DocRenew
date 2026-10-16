import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { EmployeeProfile, EmployeeProfileFormPayload, PaginatedResponse } from '../../core/models';

/**
 * T33 — Employee profile UI. All calls hit EmployeeProfileController
 * (already built, FSD 3.2/3.3/4.4): visibility is scoped server-side by
 * EmployeeProfile::visibleTo() (Admin/HR see the whole tenant, a Manager
 * sees themselves + direct reports, an Employee sees only themselves),
 * so this service never needs to duplicate that scoping client-side.
 */
@Injectable({ providedIn: 'root' })
export class EmployeesService {
  constructor(private readonly http: HttpClient) {}

  list(
    filters: { department?: string; active?: boolean; search?: string } = {},
    page = 1,
    perPage = 50,
  ): Observable<PaginatedResponse<EmployeeProfile>> {
    let params = new HttpParams().set('page', page).set('per_page', perPage);
    if (filters.department) params = params.set('department', filters.department);
    if (filters.active !== undefined) params = params.set('active', filters.active);
    if (filters.search) params = params.set('search', filters.search);

    return this.http.get<PaginatedResponse<EmployeeProfile>>(`${environment.apiUrl}/employee-profiles`, { params });
  }

  /** Unfiltered, high-per_page listing used to populate manager pickers. */
  listAll(): Observable<PaginatedResponse<EmployeeProfile>> {
    return this.http.get<PaginatedResponse<EmployeeProfile>>(`${environment.apiUrl}/employee-profiles`, {
      params: new HttpParams().set('per_page', 200),
    });
  }

  get(id: string): Observable<{ data: EmployeeProfile }> {
    return this.http.get<{ data: EmployeeProfile }>(`${environment.apiUrl}/employee-profiles/${id}`);
  }

  create(payload: EmployeeProfileFormPayload): Observable<{ data: EmployeeProfile }> {
    return this.http.post<{ data: EmployeeProfile }>(`${environment.apiUrl}/employee-profiles`, payload);
  }

  update(id: string, payload: EmployeeProfileFormPayload): Observable<{ data: EmployeeProfile }> {
    return this.http.patch<{ data: EmployeeProfile }>(`${environment.apiUrl}/employee-profiles/${id}`, payload);
  }

  deactivate(id: string): Observable<{ data: EmployeeProfile }> {
    return this.http.post<{ data: EmployeeProfile }>(`${environment.apiUrl}/employee-profiles/${id}/deactivate`, {});
  }

  reactivate(id: string): Observable<{ data: EmployeeProfile }> {
    return this.http.post<{ data: EmployeeProfile }>(`${environment.apiUrl}/employee-profiles/${id}/reactivate`, {});
  }
}
