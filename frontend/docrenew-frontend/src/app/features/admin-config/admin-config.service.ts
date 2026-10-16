import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ReminderScheduleRow, TenantSettings } from '../../core/models';

/**
 * T34 — Admin/config UI. Talks to T26's ReminderScheduleConfigController
 * and TenantSettingsController (FSD 4.5). Both endpoints are Admin-edit /
 * HR-view-only (ReminderSchedulePolicy / TenantPolicy) — this service
 * doesn't enforce that itself, the components do, matching the same
 * split the backend policies already draw.
 */
@Injectable({ providedIn: 'root' })
export class AdminConfigService {
  constructor(private readonly http: HttpClient) {}

  getReminderSchedules(): Observable<{ data: ReminderScheduleRow[] }> {
    return this.http.get<{ data: ReminderScheduleRow[] }>(`${environment.apiUrl}/config/reminder-schedules`);
  }

  /**
   * Upserts a batch of rows keyed by (document_type_id, stage), matching
   * ReminderScheduleConfigController::update()'s own batch-upsert design —
   * a "Save changes" click sends every default-stage row plus every
   * override row in one call, rather than one PATCH per row.
   */
  updateReminderSchedules(rows: ReminderScheduleRow[]): Observable<{ data: ReminderScheduleRow[] }> {
    const schedules = rows.map((r) => ({
      document_type_id: r.document_type_id,
      stage: r.stage,
      offset_days: r.offset_days,
      cadence_days: r.stage === 'ESCALATION' ? r.cadence_days : null,
      channels: r.channels,
      active: r.active,
    }));
    return this.http.patch<{ data: ReminderScheduleRow[] }>(`${environment.apiUrl}/config/reminder-schedules`, {
      schedules,
    });
  }

  getSettings(): Observable<{ data: TenantSettings }> {
    return this.http.get<{ data: TenantSettings }>(`${environment.apiUrl}/config/settings`);
  }

  updateSettings(payload: Partial<Omit<TenantSettings, 'id'>>): Observable<{ data: TenantSettings }> {
    return this.http.patch<{ data: TenantSettings }>(`${environment.apiUrl}/config/settings`, payload);
  }
}
