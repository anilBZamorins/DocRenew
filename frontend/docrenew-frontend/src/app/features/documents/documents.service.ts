import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { DocumentFormPayload, DocumentRecord, DocumentType, EmployeeProfile } from '../../core/models';

/**
 * T32 — document create/edit/view/attachment calls, shared by the
 * dashboard's quick-renew action (T31) and the new document form/detail
 * screens. Everything here hits endpoints DocumentController (T20) and
 * AttachmentController (T21) already exposed; T32 only adds the
 * document-types listing (DocumentTypeController) those forms need.
 */
@Injectable({ providedIn: 'root' })
export class DocumentsService {
  constructor(private readonly http: HttpClient) {}

  listDocumentTypes(): Observable<{ data: DocumentType[] }> {
    return this.http.get<{ data: DocumentType[] }>(`${environment.apiUrl}/document-types`);
  }

  /** For the owner picker on the create form (Admin/HR only — see DocumentFormComponent). */
  listEmployeeProfiles(): Observable<{ data: EmployeeProfile[] }> {
    return this.http.get<{ data: EmployeeProfile[] }>(`${environment.apiUrl}/employee-profiles`, {
      params: new HttpParams().set('per_page', 200).set('active', 'true'),
    });
  }

  getDocument(id: string): Observable<{ data: DocumentRecord }> {
    return this.http.get<{ data: DocumentRecord }>(`${environment.apiUrl}/documents/${id}`);
  }

  createDocument(payload: DocumentFormPayload): Observable<{ data: DocumentRecord }> {
    return this.http.post<{ data: DocumentRecord }>(`${environment.apiUrl}/documents`, payload);
  }

  updateDocument(id: string, payload: Partial<DocumentFormPayload>): Observable<{ data: DocumentRecord }> {
    return this.http.patch<{ data: DocumentRecord }>(`${environment.apiUrl}/documents/${id}`, payload);
  }

  deleteDocument(id: string): Observable<void> {
    return this.http.delete<void>(`${environment.apiUrl}/documents/${id}`);
  }

  renewDocument(id: string, expiryDate: string, documentNumber?: string, issueDate?: string): Observable<{ data: DocumentRecord }> {
    const payload: Record<string, string> = { expiry_date: expiryDate };
    if (documentNumber) payload['document_number'] = documentNumber;
    if (issueDate) payload['issue_date'] = issueDate;
    return this.http.post<{ data: DocumentRecord }>(`${environment.apiUrl}/documents/${id}/renew`, payload);
  }

  /** FSD 4.1 "shown in full only on explicit reveal, audit-logged." */
  revealNumber(id: string): Observable<{ id: string; document_number: string }> {
    return this.http.get<{ id: string; document_number: string }>(`${environment.apiUrl}/documents/${id}/reveal-number`);
  }

  /** Mints a short-lived signed download URL — never a direct storage path (FSD 10). */
  getAttachmentDownloadUrl(id: string): Observable<{ url: string; expires_in: number }> {
    return this.http.get<{ url: string; expires_in: number }>(`${environment.apiUrl}/documents/${id}/attachment`);
  }

  /** Also used to REPLACE an existing attachment — AttachmentController::store() handles both. */
  uploadAttachment(id: string, file: File): Observable<{ data: DocumentRecord }> {
    const formData = new FormData();
    formData.append('file', file);
    return this.http.post<{ data: DocumentRecord }>(`${environment.apiUrl}/documents/${id}/attachment`, formData);
  }
}
