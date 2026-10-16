export type UserRole = 'ADMIN' | 'HR' | 'MANAGER' | 'EMPLOYEE';

export interface AuthenticatedUser {
  id: string;
  email: string;
  role: UserRole;
  status: string;
  tenant_id: string;
  employee_profile: EmployeeProfile | null;
  last_login_at: string | null;
}

export interface EmployeeProfile {
  id: string;
  name: string;
  employee_code: string | null;
  department: string | null;
  job_title: string | null;
  manager_id: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  active: boolean;
  // Only present on EmployeeProfileController::show() (eager-loaded there,
  // not on index()) — T33's list view hydrates it client-side instead.
  has_user_account?: boolean;
  created_at?: string;
  updated_at?: string;
}

/**
 * T33 — payload for EmployeeProfileController::store()/update(). Every
 * field is optional because UpdateEmployeeProfileRequest narrows what an
 * Employee may send (contact_email/contact_phone only) — the form only
 * includes the fields the acting role is allowed to edit.
 */
export interface EmployeeProfileFormPayload {
  name?: string;
  employee_code?: string | null;
  department?: string | null;
  job_title?: string | null;
  manager_id?: string | null;
  contact_email?: string | null;
  contact_phone?: string | null;
}

export interface DashboardSummary {
  valid: number;
  expiring_soon: number;
  expiring_urgent: number;
  expired: number;
  renewed_last_30d: number;
  as_of: string;
}

/** Matches DocumentController::index()'s computed status accessor values. */
export type DocumentStatus = 'VALID' | 'EXPIRING_SOON' | 'EXPIRING_URGENT' | 'EXPIRED' | 'RENEWED';

export interface DocumentRecord {
  id: string;
  employee_profile_id: string | null;
  document_type_id: string;
  document_type: string | null;
  category: string;
  item_label: string | null;
  document_number_masked: string | null;
  issue_date: string | null;
  expiry_date: string | null;
  attachment: { file_name: string; mime_type: string; size_bytes: number; scanned_at: string | null } | null;
  status: DocumentStatus;
  last_renewed_at: string | null;
  escalation_dismissed: { reason: string; dismissed_at: string; dismissed_by: string } | null;
  created_at: string;
  updated_at: string;
  // Hydrated client-side after the employee-profiles lookup, for display only.
  employee_name?: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
  links?: unknown;
}

export interface DocumentFilters {
  status?: DocumentStatus | '';
  document_type_id?: string | '';
  search?: string;
  employee_profile_id?: string;
}

export type DocumentCategory = 'EMPLOYEE_DOCUMENT' | 'EXTENDED_ITEM';

export interface DocumentType {
  id: string;
  name: string;
  category: DocumentCategory;
  sort_order: number;
  retired: boolean;
}

/** Payload shape for both create (StoreDocumentRequest) and edit (UpdateDocumentRequest). */
export interface DocumentFormPayload {
  employee_profile_id?: string | null;
  document_type_id: string;
  category?: DocumentCategory;
  item_label?: string | null;
  document_number?: string | null;
  issue_date?: string | null;
  expiry_date: string;
}
