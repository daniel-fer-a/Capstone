# CCHIA · Backend de postulaciones

Entrega del 16/10/2026 (hora de Chile), preparada con la ERS del mismo día y las aclaraciones del chat. Todos los archivos se entregan a Daniel para revisión y carga manual. No se modifica Drive ni se publica un servicio.

## Contenido

- `backend/`: Laravel 13, Composer y lockfile reales, Sanctum, migraciones, modelos, validaciones, servicios, políticas y pruebas.
- `GUIA_INSTALACION.md`: pasos para instalación, conexión y pruebas.
- `REVISION_STACK_ERS_16_10.md`: contraste de las páginas 14–19 y versiones utilizadas.
- `MODELO_POSTULACIONES.md`: tablas, relaciones y decisión UUID/varchar.
- `API_POSTULACIONES.md`: contrato para integrar React después.
- `CHECKLIST.md` y `VERIFICACION.md`: avances, pendientes y resultados precisos.

## Resultado

La API recibe una solicitud, valida identidad y modalidad, guarda datos y antecedentes privados, comprueba patrocinadores mediante el Modelo 02 y registra el envío. Devuelve un folio y un código de seguimiento. No crea cuentas, asigna roles ni concede membresía automáticamente.

**PostgreSQL objetivo: 18.x**, siguiendo RT-03 y tu aclaración. El anexo A-04 del mismo PDF todavía dice 16. Esta entrega sustituye la referencia técnica a PostgreSQL 16 de la entrega anterior para el trabajo que continúa desde aquí.

Se trata de una base NUEVA para una base de datos vacía de desarrollo. No superponerla al proyecto existente: incluye contratos mínimos de users, companies, commissions y resources para las FK del Modelo 02. La primera migración rechaza esas tablas si ya existen. El SQL Modelo 02 recibido se conserva sin cambios; su adaptación se realiza en migraciones separadas.

No se incluyen vendor, .env, credenciales, cuentas predeterminadas ni datos reales. Los archivos .env.example tienen nombres de ejemplo y campos vacíos para completar localmente.

La interfaz React, el inicio de sesión, la recuperación de acceso, la consulta pública de estado y la resolución del Directorio siguen pendientes del siguiente bloque. El código de seguimiento queda guardado mediante hash; su endpoint público todavía no existe.

Las lecturas institucionales están protegidas por Sanctum y el Modelo 02, y deshabilitadas por configuración hasta definir la política sensible. No se instala un acceso administrativo de demostración. La ruta del colaborador jurídico también está deshabilitada por defecto hasta confirmar sus antecedentes.
