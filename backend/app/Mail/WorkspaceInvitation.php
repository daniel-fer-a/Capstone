<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class WorkspaceInvitation extends Mailable
{
    public function __construct(public string $invitationId,public string $privateCode,public string $commissionName,public string $roleName,public string $expiresAt) {}
    public function build()
    {
        return $this->subject('Invitación privada a una comisión CCHIA')->view('mail.workspace-invitation',[
            'invitationUrl'=>rtrim(config('admissions.frontend_url'),'/').'/invitacion/'.$this->invitationId,
        ]);
    }
}
