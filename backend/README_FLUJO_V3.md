# Postulación con patrocinio privado

La implementación vigente incorpora una etapa privada anterior al envío documental. Los documentos se habilitan cuando dos socios distintos, activos y vigentes confirman desde sus cuentas.

## Personas jurídicas

Datos de organización y representante: razón social, RUT, nombre completo y RUT del representante, correo, región, comuna y actividad en IA. Después de los patrocinios se exigen exactamente estatutos y certificado de vigencia. La personería no es un tercer adjunto del nuevo formulario.

## Uso

- Crear la etapa con `POST /api/v1/intakes`, dos `sponsorEmails`, aceptación de declaración y autorización para compartir el resumen. Enviar una clave `Idempotency-Key`.
- Conservar el folio privado y código. Retomar mediante `POST /api/v1/intakes/resume` con `reference` y `access_token` en el cuerpo.
- Cada socio consulta y responde sus propias invitaciones mediante `/api/v1/sponsor/invitations` con sesión y protección CSRF.
- Renovar los dos patrocinios mediante `/api/v1/intakes/sponsors`, con confirmación explícita y clave de operación. Se conserva la evidencia anterior.
- Enviar documentos y declaración final mediante `/api/v1/intakes/submit`. Los datos del postulante se recuperan de su etapa cifrada y las confirmaciones se vuelven a verificar.
- Consultar la postulación recibida mediante el folio formal y código de seguimiento en `/api/v1/applications/track`.

El endpoint anterior `POST /api/v1/applications` no permite crear una nueva postulación institucional de socio pasando solamente IDs de patrocinadores. Se conserva su piloto natural en `local/testing` y los recorridos anteriores para compatibilidad controlada.

## Revisión y resolución

`ADMISSIONS_REVIEW_ENABLED` permanece deshabilitado de forma predeterminada. El Directorio requiere asignación vigente y permisos explícitos. `admissions.review.start` permite iniciar revisión; `admissions.decisions.record`, junto con lectura, permite registrar el acuerdo ya adoptado, con fundamento, fecha de sesión y referencia de acta. La resolución inicial se conserva sin sobrescribirse. La aprobación no asigna membresía.

## Operación local

```bash
php artisan migrate
php artisan admissions:diagnose
php artisan admissions:local-user socio.prueba@example.test --sponsor
php artisan admissions:local-user revision.prueba@example.test --reviewer
php artisan admissions:send-notifications --limit=25
php artisan admissions:notifications-status
php artisan schedule:work
```

Los comandos de creación de cuentas funcionan únicamente en local o pruebas y solicitan una contraseña. No ejecutan seeders ni crean cuentas de forma automática. El seeder de desarrollo de Fabián se conserva.

El servicio `scheduler` de Compose ejecuta el planificador usando el mismo `.env`, clave y volúmenes privados que la API. `MAIL_MAILER=log` y `array` no entregan correo real. El transporte SMTP debe configurarse y validarse antes de uso institucional.

## Persistencia y validación

Se conservan las siete migraciones recibidas y se agregan dos. Los antecedentes iniciales se cifran con la `APP_KEY`, que debe mantenerse entre reinicios y actualizaciones. Los códigos se autentican mediante hash y sus copias de recuperación se purgan después de 24 horas. La identidad patrocinada y los acuerdos conservan su historial.

Mantener PostgreSQL 18 y una base independiente terminada en `_test` para ejecutar `php artisan test`. Los cuatro casos `native-client` deben ejecutarse sobre PostgreSQL en Docker. La guía de la entrega contiene el procedimiento completo y la checklist manual.

El inicio del plazo institucional de revisión debe acordarse con CCHIA. La etapa de patrocinios y el envío de la postulación conservan sus fechas por separado. Las apelaciones ante la Asamblea, subsanación versionada y formalización corresponden a la continuidad siguiente.
