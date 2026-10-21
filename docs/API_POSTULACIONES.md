# Contrato de la API · base 1

## Registro público

POST /api/v1/applications. Accept: application/json. Para una solicitud sin documentos puede usarse JSON. Con archivos debe utilizarse multipart/form-data con los bytes de cada archivo; el prototipo anterior solo simulaba sus metadatos.

| Campo | Condición |
|---|---|
| membership | member o collaborator. |
| legal | natural o juridica. |
| name, identifier | Obligatorios para natural; no mezclar con datos jurídicos. |
| organizationName, organizationId, representativeName, representativeId | Obligatorios para jurídica; no mezclar con identidad natural. |
| email, address, aiActivity | Obligatorios en todas las modalidades. |
| declaration_accepted | Debe ser true; confirma revisión de datos, no es una política jurídica de consentimiento. |
| sponsors[0], sponsors[1] | Para jurídica: identificadores reales de dos socios distintos del Modelo 02. No usar ACT-001 del prototipo. |
| documents[vigencia], documents[personeria], documents[estatutos] | Archivos obligatorios para jurídica. estatutos también admite el respaldo de inicio de actividades. |
| documents[antecedentes] | Archivo adicional opcional. |

La ruta collaborator + juridica está deshabilitada por defecto mientras se confirman sus requisitos. La ruta técnica preparada, si se habilita tras esa confirmación, exige los antecedentes y patrocinadores jurídicos. Esto no concede membresía al colaborador.

Archivos: PDF/JPG/PNG, máximo 5 MB por archivo y cuatro tipos de antecedentes. Son límites técnicos propuestos. Se comprueba contenido MIME, extensión y tamaño. Las identidades aceptan identificación nacional o extranjera; no se implementa todavía una validación específica de dígito verificador de RUT.

Respuesta HTTP 201:

```json
{
  "data": {
    "id": "UUID generado",
    "reference": "CCHIA-folio generado",
    "status": "received",
    "submitted_at": "fecha ISO 8601",
    "tracking_token": "código aleatorio entregado solo en esta respuesta"
  },
  "message": "Postulación recibida. Conserva el folio y el código de seguimiento; no otorga membresía automática."
}
```

La respuesta no contiene la identidad, las claves de almacenamiento ni el hash del código. No guardar el código en logs o capturas públicas. Si se pierde, no hay aún un flujo implementado de recuperación del seguimiento.

Errores: 422 para datos o patrocinadores inválidos; 429 para más de cinco intentos por minuto desde la misma IP, como límite inicial de desarrollo; 500 para fallos internos. El backend no permite al formulario definir roles, estado, folio, identificador de solicitud o resolución.

El registro usa una transacción para solicitud, documentos, patrocinio y evento. Si falla una escritura conocida, revierte los datos y elimina archivos escritos en ese intento. La recuperación ante interrupciones abruptas entre base y almacenamiento necesita el procedimiento operativo posterior.

## Lecturas institucionales preparadas

- GET /api/v1/admin/applications/{application}
- GET /api/v1/admin/applications/{application}/documents/{document}

Ambas requieren Sanctum y política habilitada. La primera exige admissions.read; la segunda también admissions.documents.read. Los permisos deben corresponder a una asignación Directorio global activa, no revocada y vigente. Por defecto no se conceden y ADMISSIONS_REVIEW_ENABLED=false. Un documento solicitado bajo otra postulación devuelve 404.

No hay aún rutas de login, recuperación de contraseña, listado de bandeja, resolución o consulta pública. Las pruebas utilizan Sanctum::actingAs para comprobar autorización; eso no acredita un inicio de sesión real.

## Adaptación futura de React

Mantener los códigos membership/legal del prototipo. Sustituir checked por declaration_accepted. Recoger archivos reales en FormData y reemplazar la lista ficticia de patrocinadores por un mecanismo institucional autorizado. Mostrar estados de carga, errores por campo, éxito y folio. La integración visual sigue pendiente.
