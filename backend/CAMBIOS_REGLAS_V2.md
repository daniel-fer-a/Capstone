# Reglas 2026-10-27-base-2 (cambios sobre el paquete del 16/10)

Decisiones del equipo tras revisar la maqueta de alta fidelidad.

| Tema | Antes | Ahora |
|---|---|---|
| Persona natural: nombre | `name` | `firstNames` + `lastNames` (se conserva `natural_name` = ambos unidos) |
| Persona natural: edad | no existía | `age` entero 1–120, obligatoria |
| Socio (natural o jurídica) | natural sin patrocinio | `sponsors`: exactamente 2 **correos** de socios activos, distintos y distintos del postulante |
| Colaborador natural | sin patrocinio | sin cambio (`sponsors` está prohibido) |
| Persona jurídica: documentos | vigencia + personería + estatutos | solo `documents.estatutos` (estatutos o inicio de actividades); `antecedentes` sigue opcional |
| Comprobante | solo en pantalla | además un correo con folio y datos (nunca el código de seguimiento ni la identificación) |

## Contrato de POST /api/v1/applications (natural, socio)
multipart/form-data: membership=member, legal=natural, firstNames, lastNames, age, identifier, email,
address (región y comuna), aiActivity, declaration_accepted=1, sponsors[0], sponsors[1],
[documents[antecedentes]].

Los patrocinios no verificables devuelven un único error 422 en `sponsors` sin indicar cuál correo falló
(para no revelar quién es socio).

## Compatibilidad
Las solicitudes guardadas con `rules_version = 2026-10-16-base-1` se conservan y siguen la regla anterior.
Las nuevas llevan `2026-10-27-base-2`.

## Datos de desarrollo
`DEV_SEED_PASSWORD` en .env (12+ caracteres) y luego `php artisan db:seed --class=DevSeeder` crea
socio1@example.org, socio2@example.org y directorio@example.org. Solo con APP_ENV=local.
