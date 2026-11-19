<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdmissionUpdate extends Mailable
{
    public function __construct(public readonly string $reference, public readonly string $action) {}

    public function envelope(): Envelope { return new Envelope(subject: 'Actualización de tu postulación CCHIA'); }
    public function content(): Content
    {
        return new Content(text: 'mail.admission-update', with: ['reference' => $this->reference,
            'url' => rtrim(config('admissions.frontend_url'), '/').'/seguimiento']);
    }
}
