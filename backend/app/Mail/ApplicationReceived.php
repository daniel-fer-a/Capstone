<?php

namespace App\Mail;

use App\Models\AdmissionApplication;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Comprobante por correo. Nunca incluye el código de seguimiento (es secreto y se
 * entrega una sola vez en pantalla) ni la identificación del postulante.
 */
class ApplicationReceived extends Mailable
{
    public function __construct(public readonly AdmissionApplication $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'CCHIA · Recibimos tu postulación '.$this->application->reference);
    }

    public function content(): Content
    {
        $a = $this->application;
        $natural = $a->legal === 'natural';

        return new Content(text: 'mail.application-received', with: [
            'folio' => $a->reference,
            'fecha' => $a->submitted_at->setTimezone('America/Santiago')->format('d-m-Y H:i'),
            'vinculo' => $a->membership === 'member' ? 'Socio' : 'Colaborador',
            'persona' => $natural ? 'Persona natural' : 'Persona jurídica',
            'nombre' => $natural ? $a->natural_name : $a->organization_name,
            'representante' => $natural ? null : $a->representative_name,
            'correo' => $a->email,
            'ubicacion' => $a->address,
            'actividad' => $a->ai_activity,
        ]);
    }
}
