# Backend CCHIA Postulaciones

Versión de continuidad consolidada el 3 de noviembre de 2026 sobre el ZIP exacto del 1 de noviembre proporcionado por Daniel. PHP 8.4, Laravel 13, PostgreSQL 18. Las dependencias conservan sus locks. La nueva validación nativa de Fabián está pendiente.

Incluye patrocinios privados, recepción y seguimiento, revisión, subsanación documental con versiones, apelación ante el Directorio y registro del acuerdo de la Asamblea. El ingreso aprobado se registra expresamente con una bandera y permiso separados; no crea cuentas ni otorga roles.

El paquete de entrega contiene la guía de integración, la matriz de QA y el contrato de rutas. `README_FLUJO_V3.md` y `CAMBIOS_REGLAS_V2.md` se conservan como antecedentes de entregas anteriores. El flujo actual y sus pendientes se describen en los anexos V4 del paquete.

No ejecutar pruebas contra la base de trabajo. La suite requiere PostgreSQL y una base cuyo nombre termine en `_test`; los casos de concurrencia limpian el esquema de postulaciones de esa base desechable. Ejecutar `php vendor/bin/phpunit` en el entorno de pruebas. Los comandos `admissions:diagnose`, `admissions:notifications-status` y `admissions:release-check` son de diagnóstico; este último no sustituye QA ni despliega servicios.

## Incremento del 3 de noviembre

Añade consulta del registro de ingresos formalizados, ficha y CSV con permisos específicos, gestión de avisos y reactivación justificada de fallos agotados. El historial de reactivaciones es inmutable y la operación permite recuperar una respuesta perdida sin duplicar su registro. Las diez migraciones previas permanecen intactas; se agrega una migración que incorpora la tabla y tres permisos al catálogo, sin concederlos automáticamente.

`php artisan cchia:ops` ofrece diagnóstico de integridad de archivos, permisos, avisos y ejecución reciente del scheduler. La tarea de latido se programa cada minuto. `../operacion` contiene una plantilla separada de QA y scripts de respaldo/restauración que requieren validación real de Docker; no reemplazan el entorno existente. La guía vigente es `documentacion/VALIDACION_CORTA_FABIAN.md` en la raíz del paquete.
