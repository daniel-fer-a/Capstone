<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SponsorshipInvitation extends Mailable
{
    public function __construct(public readonly string $applicantName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CCHIA · Solicitud privada de patrocinio');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.sponsorship-invitation', with: [
            'applicant' => $this->applicantName,
            'url' => rtrim(config('admissions.frontend_url'), '/').'/area/patrocinios',
        ]);
    }
}
