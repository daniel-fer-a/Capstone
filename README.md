# Proyecto Capstone CCHIA

Proyecto de Ingeniería en Informática de Duoc UC desarrollado para CCHIA. La entrega prioriza el ingreso de socios: postulación de personas naturales y jurídicas, patrocinio, documentos, seguimiento y revisión institucional.

La aplicación integra React y TypeScript con una API Laravel y PostgreSQL 18. El flujo solicita la aceptación expresa de dos socios distintos y vigentes antes de habilitar documentos. Para personas jurídicas se solicitan estatutos y vigencia, junto con nombre completo y RUT del representante. Las decisiones del Directorio, las subsanaciones, la apelación y la formalización conservan su historial.

## Equipo

- **Fabián:** programación, integración, instalación y comprobaciones técnicas.
- **Daniel Fernández Arce:** documentación, planificación, cronograma y seguimiento de entregables.
- **Eduardo:** apoyo a programación y documentación, revisión de recorridos y validaciones.

## Organización del repositorio

| Carpeta | Contenido |
|---|---|
| `Fase 1` | Documentación de la primera fase académica. |
| `Fase 2` | Documentación de la segunda fase académica. |
| `Fase 3` | Documentación de la tercera y ultima fase académica. |
| `backend` | Código y recursos de implementación conservados por el equipo. |
| `docs` | Documentación técnica, evidencias y maqueta navegable. |

Los cronogramas se conservan en la raíz. Para el seguimiento se utiliza el cronograma quincenal vigente, con tres sprints y cierre previsto el 21 de noviembre de 2026.

## Documentación e instalación

La referencia principal es [Documentación general de CCHIA](docs/Documentacion_General_CCHIA_V3.0.docx). Reúne el trabajo realizado, el funcionamiento, la arquitectura, la instalación, la operación, la API, las pantallas, el modelo de datos, las pruebas y el plan de piloto y cierre, es un documento en constante actualización para hacer seguimiento a los cambios realizados. Los documentos anteriores de `docs` se conservan como antecedentes.

La [maqueta navegable](docs/Maqueta_Alta_Fidelidad_V1.2.html) permite consultar la propuesta de interfaz; su navegación no sustituye las comprobaciones de la aplicación integrada.

El entorno utiliza PHP 8.4, Laravel 13, PostgreSQL 18 y Node 24. Se instalan las dependencias desde `composer.lock` y `package-lock.json`. Las instrucciones y los comandos están en el Word, con indicación de la carpeta y el ambiente en que se ejecutan. Se conserva la configuración existente y los ajustes del equipo.

## Estado al 3 de noviembre de 2026

La base de operación y continuidad está integrada y Fabián confirmó que las pruebas pasaron sin cambios adicionales. El trabajo siguiente es acreditar el ambiente de validación, ejecutar el piloto, resolver sus hallazgos y consolidar los resultados en la entrega final.

S2 termina el 13 de noviembre con la versión disponible para piloto. S3 comprende piloto, correcciones, memoria y manuales del 14 al 21. Las ampliaciones generales fuera del flujo priorizado no se agregan como pendientes de ese cronograma.

La configuración efectiva, claves, códigos de acceso, documentos de postulantes, respaldos y registros sensibles se mantienen fuera del repositorio.
