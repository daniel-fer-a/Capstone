<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetAccessPassword extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Recupera tu acceso a CCHIA')
            ->greeting('Hola:')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
            ->action('Establecer nueva contraseña', $url)
            ->line('Este enlace vence en '.config('auth.passwords.users.expire').' minutos y solo puede utilizarse una vez.')
            ->line('Si no hiciste esta solicitud, puedes ignorar este mensaje.')
            ->salutation('CCHIA');
    }
}
