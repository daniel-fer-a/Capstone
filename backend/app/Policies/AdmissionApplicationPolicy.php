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

    public function downloadDocument(User $user, AdmissionApplication $application): bool
    {
        $allowed = $this->access->reviewerHasPermission($user->id, 'admissions.read')
            && $this->access->reviewerHasPermission($user->id, 'admissions.documents.read');
        $this->audit->record($user, 'admissions.documents.read', $application->id, $allowed ? 'allowed' : 'denied');

        return $allowed;
    }
}
