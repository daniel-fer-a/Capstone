# Revisión del stack · ERS 16/10/2026

Se revisaron nuevamente las páginas 14, 15, 16, 17, 18 y 19 de ERS-CCHIA-2026-10-16.pdf. La ERS continúa aprobada y no fue editada.

| Componente | Página 14 | Anexo A-04, página 18 | Referencia de trabajo |
|---|---|---|---|
| Laravel | RT-02: encabezado 13; descripción 11 LTS | 13 | **13.x**, siguiendo encabezado y anexo. La descripción es inconsistente. |
| PHP | RT-02: encabezado 8.3; descripción 8.5 | 8.4 | **8.4.x**, referencia del anexo. |
| PostgreSQL | RT-03: 18 como versión objetivo | 16 | **18.x**, por RT-03 y tu aclaración en este chat. |
| React | RT-01: 18 | 19 | **19.x**, referencia del anexo para el frontend. |
| Vite | Sin versión | 8 | **8.x**. Frontend pendiente de integración. |
| TypeScript | Sin versión | 6 | **6.x**. Frontend pendiente de integración. |
| Node.js | Sin versión | 24 LTS | **24 LTS**. |
| Tailwind | Sistema de estilos aprobado, sin versión | 4.1 o superior | Base **4.1+ dentro de 4.x**, sin saltar a otra versión mayor automáticamente. |

PostgreSQL 18 reemplaza la referencia 16 de nuestra entrega anterior. Para las demás contradicciones se conserva el anexo como referencia de implementación; no se afirma que ambas páginas sean consistentes. Las versiones exactas del backend están en composer.lock. Las del frontend siguen siendo una referencia, no un frontend construido en este bloque.

## Infraestructura que sigue vigente

| Requisito | ERS | Estado de esta entrega |
|---|---|---|
| Docker y Compose | RT-04 dice «24 o superior» para ambos | Sus numeraciones son independientes. Verificar Engine y Compose por separado; no interpretar «Compose 24» literalmente. El perfil incluido es de desarrollo y no se ejecutó aquí. |
| Nginx y TLS | RT-05, proxy para frontend, API y Reverb | Pendiente de despliegue. El servidor PHP de desarrollo no acredita Nginx/TLS. |
| Redis | RT-06: Redis 7 | Se conserva **7.x** como referencia. El perfil local usa archivos y cola síncrona, no demuestra Redis. |
| Reverb | RT-07 | Pendiente. No es necesario para probar el registro HTTP de esta solicitud. |
| S3 compatible | RT-08 y RNF-PO-03 | Adaptador instalado y disco configurable local/S3. Pruebas con archivos privados locales; bucket autorizado pendiente. |
| VPS Hostinger KVM 4 | RT-09, dimensionamiento sujeto a pruebas | No se contrata ni despliega. Las cifras del PDF son referencias del proyecto, no una comprobación del plan comercial actual. |
| Sanctum | RT-10 | Instalado y utilizado en rutas protegidas. Inicio de sesión y recuperación pendientes. |

## Otras exigencias de las páginas revisadas

- RT-11: migraciones y datos de prueba reproducibles. Se incluyen migraciones y fixtures dentro de pruebas, sin crear cuentas con privilegios en la instalación.
- RT-12: pruebas e integración progresiva end-to-end. Las pruebas del backend no acreditan el frontend ni el MVP completo.
- RT-13: CI/CD y monitoreo. Pendientes; no se habilitan pipelines ni publicaciones.
- RNF-CO-02: transacciones para operaciones críticas. Solicitud, metadatos y evento se guardan en una transacción. Los archivos tienen limpieza compensatoria ante excepciones, sin prometer una transacción distribuida ante un corte abrupto.
- RNF-SE-01/03: rol, permiso, ámbito y vigencia. Las rutas sensibles permanecen deshabilitadas; requieren permisos explícitos y asignaciones vigentes.
- RNF-SE-05: descargas autorizadas. No se generan URLs públicas de los antecedentes.
- La meta de 300 usuarios simultáneos exige pruebas de carga; esta entrega no demuestra esa capacidad.
- Respaldos, restauración, producción, monitoreo y validación con usuarios siguen pendientes del cronograma.

## Soporte hasta 2028

La frase del anexo y la página 19 no garantiza el mismo periodo para todos los componentes. Laravel 13 declara seguridad hasta **17/03/2028**; PHP 8.4 hasta **31/12/2028**; Node 24 hasta finales de **abril de 2028**; PostgreSQL 18 hasta **14/11/2030**. Vite mantiene ramas según su política de versiones soportadas. No se atribuye una fecha común a React, TypeScript y Tailwind.

El PHP de las pruebas es 8.4.1. No se distribuye ese binario como runtime productivo. Para una instalación real deben mantenerse los parches vigentes de cada rama y auditar las dependencias.

Fuentes oficiales consultadas:

- https://laravel.com/docs/13.x/releases
- https://www.php.net/supported-versions.php
- https://nodejs.org/en/blog/migrations/v22-to-v24
- https://www.postgresql.org/support/versioning/
- https://vite.dev/releases
- https://vite.dev/guide/
- https://docs.docker.com/compose/
- https://github.com/docker-library/docs/blob/master/postgres/content.md
