<?php

namespace App\Policies;

use App\Models\AdmissionApplication;
use App\Models\User;
use App\Services\AuthorizationAudit;
use App\Services\Model02Access;

class AdmissionApplicationPolicy
{
    public function __construct(private readonly Model02Access $access, private readonly AuthorizationAudit $audit) {}

    public function view(User $user, AdmissionApplication $application): bool
    {
        $allowed = $this->access->reviewerHasPermission($user->id, 'admissions.read');
        $this->audit->record($user, 'admissions.read', $application->id, $allowed ? 'allowed' : 'denied');

        return $allowed;
    }

    public function viewAny(User $user): bool
    {
        $allowed = $this->access->reviewerHasPermission($user->id, 'admissions.read');
        $this->audit->record($user, 'admissions.list', 'queue', $allowed ? 'allowed' : 'denied');

        return $allowed;
    }

    public function startReview(User $user, AdmissionApplication $application): bool
    {
        $allowed = $this->independent($user, $application) && $this->access->reviewerHasPermission($user->id, 'admissions.read')
            && $this->access->reviewerHasPermission($user->id, 'admissions.review.start');
        $this->audit->record($user, 'admissions.review.start', $application->id, $allowed ? 'allowed' : 'denied');

        return $allowed;
    }

    public function downloadDocument(User $user, AdmissionApplication $application): bool
    {
        $allowed = $this->access->reviewerHasPermission($user->id, 'admissions.read')
            && $this->access->reviewerHasPermission($user->id, 'admissions.documents.read');
        $this->audit->record($user, 'admissions.documents.read', $application->id, $allowed ? 'allowed' : 'denied');

        return $allowed;
    }

    public function recordDecision(User $user, AdmissionApplication $application): bool
    {
        $allowed = $this->independent($user, $application) && $this->access->reviewerHasPermission($user->id, 'admissions.read')
            && $this->access->reviewerHasPermission($user->id, 'admissions.decisions.record');
        $this->audit->record($user, 'admissions.decisions.record', $application->id, $allowed ? 'allowed' : 'denied');
        return $allowed;
    }
    public function manageCorrections(User $user, AdmissionApplication $application): bool
    {
        return $this->casePermission($user, $application, 'admissions.corrections.manage');
    }

    public function recordAppeal(User $user, AdmissionApplication $application): bool
    {
        return $this->casePermission($user, $application, 'admissions.appeals.record');
    }

    public function formalize(User $user, AdmissionApplication $application): bool
    {
        return $this->casePermission($user, $application, 'admissions.membership.formalize');
    }

    private function independent(User $user, AdmissionApplication $application): bool
    {
        return mb_strtolower(trim($user->email)) !== mb_strtolower(trim($application->email));
    }

    private function casePermission(User $user, AdmissionApplication $application, string $permission): bool
    {
        $allowed = $this->independent($user, $application) && $this->access->reviewerHasPermission($user->id, 'admissions.read')
            && $this->access->reviewerHasPermission($user->id, $permission);
        $this->audit->record($user, $permission, $application->id, $allowed ? 'allowed' : 'denied');
        return $allowed;
    }
}
