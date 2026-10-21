# Checklist del bloque

- [x] Revisar ERS del 16/10/2026 y Modelo 02.
- [x] Volver a contrastar páginas 14–19 y corregir PostgreSQL objetivo a 18.
- [x] Preparar base Laravel independiente con dependencias instaladas y composer.lock real.
- [x] Decidir varchar(36) para las claves heredadas y UUID para entidades nuevas, sin convertir una base existente.
- [x] Preparar y ejecutar cuatro migraciones en PostgreSQL 18 de pruebas, mediante motor WASM local.
- [x] Implementar modelos, validaciones, patrocinio, documentos privados y evento de envío.
- [x] Preparar políticas sensibles deshabilitadas por defecto, sin crear cuentas ni asignaciones.
- [x] Ejecutar las pruebas HTTP disponibles y registrar sus límites.
- [x] Entregar código, ejemplos y guías por el chat.
- [ ] Validar contigo la maqueta: no se recibieron nuevas observaciones visuales y no se presume su aprobación.
- [ ] Ejecutar la suite completa en PostgreSQL 18 nativo, incluidas las pruebas native-client pendientes aquí.
- [ ] Construir y ejecutar el perfil Docker incluido.
- [ ] Conectar esta base con el proyecto y el esquema reales del equipo.
- [ ] Confirmar reglas de colaborador jurídico y permisos institucionales sensibles antes de habilitarlos.

## Siguiente bloque funcional

1. Integrar el formulario React con la API y sus errores, archivos y folio.
2. Implementar inicio/cierre de sesión y recuperación de acceso.
3. Implementar seguimiento seguro usando folio y código; no solo correo electrónico.
4. Implementar bandeja, revisión y resolución del Directorio, con política aprobada y trazabilidad.
5. Comprobar el flujo completo de la entrega integrada prevista para el 30 de octubre en la Gantt propuesta.

Nginx/TLS, Redis 7, Reverb cuando corresponda, S3 real, CI/CD, carga, respaldos, restauración y despliegue se preparan después según el cronograma. No se publican ni se configuran en servicios externos desde esta entrega.
