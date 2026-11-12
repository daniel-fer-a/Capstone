<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><title>Invitación CCHIA</title></head><body>
<h1>Invitación privada a una comisión</h1>
<p>Has recibido una invitación para {{ $commissionName }} con el rol {{ $roleName }}.</p>
<p><a href="{{ $invitationUrl }}">Abrir la invitación</a></p>
<p>Ingresa este código privado en la pantalla de invitación:</p>
<p>{{ $privateCode }}</p>
<p>Vence el {{ $expiresAt }}. No compartas el código.</p>
<p>La aceptación es voluntaria. Esta invitación no otorga calidad de socio ni derechos de voto.</p>
</body></html>
