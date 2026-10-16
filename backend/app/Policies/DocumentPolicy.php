<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\EmployeeProfile;
use App\Models\User;

/**
 * FSD 3.2 permission matrix rows "Document records — all employees" and
 * "Document records — own documents":
 *
 *   Module / Action                 | Admin | HR   | Manager        | Employee
 *   Document records — all employees| Full  | Full | View (own team)| None
 *   Document records — own documents| Full  | Full | Full           | Full
 *
 * "Own documents" means employee_profile_id === the acting user's own
 * employee_profile_id — every role gets Full there, Manager included.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoping is done by the controller's query, not here.
    }

    public function view(User $user, Document $document): bool
    {
        return $this->canFullyManage($user, $document) || $this->isOwnTeamDocument($user, $document);
    }

    /**
     * create() is checked before a Document model exists, so the target
     * employee_profile_id (or null, for an org-owned EXTENDED_ITEM) is
     * passed explicitly: Gate::authorize('create', [Document::class, $targetEmployeeProfileId]).
     */
    public function create(User $user, ?string $targetEmployeeProfileId = null): bool
    {
        if ($user->role->isTenantWide()) {
            return true;
        }

        // Non-tenant-wide roles may only create against their own profile
        // (an EXTENDED_ITEM with no owner is Admin/HR territory only).
        return $targetEmployeeProfileId !== null && $targetEmployeeProfileId === $user->employee_profile_id;
    }

    public function update(User $user, Document $document): bool
    {
        return $this->canFullyManage($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->canFullyManage($user, $document);
    }

    public function renew(User $user, Document $document): bool
    {
        return $this->canFullyManage($user, $document);
    }

    /** FSD 4.1 "shown in full only on explicit reveal" — same reach as viewing the record at all. */
    public function revealNumber(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    /** T21 — uploading/replacing an attachment is a write, same reach as update(). */
    public function uploadAttachment(User $user, Document $document): bool
    {
        return $this->canFullyManage($user, $document);
    }

    /** T21 — downloading (via a signed URL) is a read, same reach as view(). */
    public function downloadAttachment(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * T23 — FSD "Escalation flow": "Manager/HR resolves by renewing the
     * document or dismissing the escalation." Admin/HR tenant-wide, or
     * the Manager it was actually escalated to (their own document, or a
     * direct report's) — NOT an arbitrary Manager elsewhere in the org,
     * and not a plain Employee (an employee dismissing their own
     * escalation would defeat the point of escalating past them).
     */
    public function dismissEscalation(User $user, Document $document): bool
    {
        if ($user->role === UserRole::Employee) {
            return false;
        }

        return $this->canFullyManage($user, $document) || $this->isOwnTeamDocument($user, $document);
    }

    private function canFullyManage(User $user, Document $document): bool
    {
        if ($document->tenant_id !== $user->tenant_id) {
            return false;
        }

        if ($user->role->isTenantWide()) {
            return true;
        }

        return $document->employee_profile_id !== null
            && $document->employee_profile_id === $user->employee_profile_id;
    }

    /** Manager's "View (own team)" — read-only reach over direct reports' documents. */
    private function isOwnTeamDocument(User $user, Document $document): bool
    {
        if ($document->tenant_id !== $user->tenant_id || ! $user->employee_profile_id) {
            return false;
        }

        if ($user->role !== UserRole::Manager || ! $document->employee_profile_id) {
            return false;
        }

        $owner = $document->employeeProfile ?? EmployeeProfile::find($document->employee_profile_id);

        return $owner && $owner->manager_id === $user->employee_profile_id;
    }
}
