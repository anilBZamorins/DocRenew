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
