# Capstone CCHIA

Proyecto de Aplicación de Título (APT) de **Ingeniería en Informática, Duoc UC**, desarrollado en colaboración con la **Cámara Chilena de Inteligencia Artificial (CCHIA)**.

Este repositorio reúne la documentación académica, el código y la documentación técnica del proyecto. El desarrollo prioriza el módulo de **postulación e ingreso de socios**, considerando personas naturales y jurídicas, validación de antecedentes y revisión por parte del Directorio.

> **Estado documental: 23 de octubre de 2026.** El proyecto se encuentra en desarrollo. Las funcionalidades disponibles y los pendientes se detallan a continuación.

## Objetivo

Apoyar la gestión de postulaciones a la CCHIA mediante una plataforma que permita registrar antecedentes, aplicar las validaciones correspondientes y facilitar su revisión y seguimiento, con acceso a la información según los roles y permisos definidos.

La recepción de una postulación registra una solicitud para evaluación. La admisión como socio requiere el proceso institucional correspondiente.

## Organización del repositorio

| Carpeta o archivo | Contenido y propósito |
| --- | --- |
| [Fase 1/](Fase%201/) | Documentación académica correspondiente a la primera fase del Proyecto APT. |
| [Fase 2/](Fase%202/) | Documentación académica correspondiente a la segunda fase del Proyecto APT. |
| [backend/](backend/) | API Laravel, configuración del entorno, migraciones, modelo de datos, reglas de acceso y pruebas automatizadas. |
| [docs/](docs/) | Documentación técnica del sistema y evidencias del desarrollo. |
| [Cronograma.xlsx](Cronograma.xlsx) | Archivo de planificación conservado en el repositorio. Su versión debe contrastarse con el cronograma actualizado del equipo. |
| [Stack tecnologico.pdf](Stack%20tecnologico.pdf) | Documento de referencia tecnológica. Contiene versiones anteriores que requieren conciliación con el stack vigente. |
| [README.md](README.md) | Presentación general y orientación para consultar el repositorio. |

**Fase 1 y Fase 2 corresponden a los entregables académicos.** El código de la aplicación se organiza en sus carpetas técnicas y la documentación del sistema se concentra en `docs/`.

La estructura anterior corresponde a las carpetas actualmente mostradas en el repositorio. La interfaz React forma parte del desarrollo local y su incorporación debe acompañarse de la validación de integración correspondiente.

## Stack tecnológico vigente

| Componente | Tecnología |
| --- | --- |
| Backend | PHP 8.4 y Laravel 13 |
| Base de datos | PostgreSQL 18, con extensión pgcrypto |
| Frontend | React 19 y TypeScript 6 |
| Herramienta de desarrollo del frontend | Vite 8 y Node.js 24 |
| Estilos | Tailwind CSS 4 |
| Control de acceso | Laravel Sanctum y modelo de roles y permisos Modelo 02 |
| Entorno local | Docker Desktop, WSL 2 y Docker Compose |
| Pruebas del backend | PHPUnit |
| Control de versiones | Git y GitHub |

Las versiones exactas del backend están fijadas en `backend/composer.lock`. El frontend local conserva sus versiones en `package-lock.json`, que debe incorporarse junto con su código.

El documento `Stack tecnologico.pdf` conserva referencias anteriores, entre ellas PostgreSQL 16 y React 18. La tabla anterior refleja la base técnica acordada y utilizada actualmente por el equipo.

## Estado del desarrollo

| Área | Estado al 23/10/2026 |
| --- | --- |
| Entorno local | Backend y PostgreSQL 18 instalados y comprobados en Docker. |
| Base de datos | Migraciones aplicadas; esquema de postulaciones y Modelo 02 disponibles. La conciliación con el diccionario de datos continúa en revisión. |
| API de postulaciones | Registro de solicitudes, validación de datos, almacenamiento privado de antecedentes y auditoría implementados. |
| Lectura de información privada | Rutas protegidas y políticas de acceso implementadas. La revisión institucional permanece deshabilitada en la configuración base. |
| Interfaz de socio persona natural | Primera integración preparada; pendiente de incorporación y validación en el entorno Docker del equipo. |
| Inicio de sesión y recuperación de contraseña | Pendientes de implementación. |
| Consulta de estado y bandeja del Directorio | Pendientes de implementación. |
| Postulación de persona jurídica | Backend preparado; falta definir el mecanismo privado de selección y verificación de patrocinadores para completar la interfaz. |

El backend base cuenta con evidencia de **29 pruebas aprobadas y 125 aserciones** en el entorno de Fabián. La actualización de integración añade pruebas y requiere una nueva ejecución de la suite completa. Las evidencias deben conservar su fecha, versión y entorno de ejecución.

## Desarrollo local

Las instrucciones de instalación y configuración deben consultarse en [docs/](docs/) y en la documentación incluida con cada actualización del código.

Para una instalación **ya configurada**, con Docker activo y el archivo de entorno local preparado, iniciar la base y la API desde la raíz del repositorio:

```sh
cd backend
docker compose -f compose.dev.yaml up -d db api
docker compose -f compose.dev.yaml exec api php artisan admissions:diagnose
```

La API local utiliza `http://localhost:8000`. La interfaz React utiliza `http://localhost:5173` una vez incorporada y levantada con su servicio correspondiente.

El diagnóstico permite comprobar las tablas necesarias, el almacenamiento y la configuración de revisión. Las pruebas automatizadas del backend deben ejecutarse sobre la base separada `cchia_test`.

Los valores de configuración propios de cada equipo se mantienen en archivos `.env` locales. Las plantillas `.env.example` documentan las variables necesarias; las credenciales reales deben quedar fuera del control de versiones.

## Planificación y documentación

El trabajo se organiza en tres sprints, de acuerdo con el cronograma actualizado:

| Sprint | Periodo |
| --- | --- |
| Sprint 1 | 17 al 30 de octubre de 2026 |
| Sprint 2 | 31 de octubre al 13 de noviembre de 2026 |
| Sprint 3 | 14 al 21 de noviembre de 2026 |

La planificación y los informes de avance deben distinguir entre trabajo preparado, trabajo integrado y trabajo validado. Los documentos académicos se actualizan con las evidencias que correspondan a cada fase.

Los requisitos se consultan en la **ERS aprobada y su versión vigente**, junto con los acuerdos registrados del proyecto. Los documentos anteriores se contrastan con esas fuentes antes de utilizar sus versiones, fechas o alcances como referencia.

## Equipo

| Integrante | Área principal de trabajo |
| --- | --- |
| Fabián | Programación, configuración del entorno e integración técnica. |
| Eduardo | Apoyo técnico, desarrollo y colaboración en las verificaciones. |
| Daniel | Documentación, planificación, cronograma y seguimiento de entregables. |

Las responsabilidades se complementan y se ajustan según las necesidades del proyecto.
