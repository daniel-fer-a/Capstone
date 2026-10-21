# Verificación de la entrega

Fecha de referencia: 16/10/2026, hora de Chile.

## Ejecutado

| Comprobación | Resultado |
|---|---|
| Dependencias backend | Instalación real, composer.lock con Laravel 13.37.0, Sanctum 4.3.3 y adaptador S3 3.35.3. |
| Manifiesto y plataforma | Composer validate --strict y check-platform-reqs correctos en PHP 8.4.1. |
| Auditoría Composer | Sin avisos de vulnerabilidad ni paquetes abandonados en la consulta realizada. No es una garantía futura de seguridad. |
| Sintaxis PHP | 41 archivos fuente, sin errores. |
| Migraciones | Las cuatro migraciones se ejecutan desde una base limpia, se revierten y se vuelven a ejecutar. |
| HTTP / integración Laravel | **25 casos, 117 aserciones, 0 errores y 0 fallos** en el grupo default. |
| Integridad SQL directa | Tres rechazos correctos con SQLSTATE 23514: rol en ámbito incorrecto, persona jurídica sin antecedentes e intento de modificar el historial. |
| Diccionario | Cuatro tablas de postulaciones y 45 columnas verificadas mediante information_schema. |
| SQL Modelo 02 | Copia literal; hash SHA-256 idéntico al archivo recibido. |

## Entorno de ejecución

PHP 8.4.1 real, Node 24.19.0 y un motor **PostgreSQL 18.6 compilado a WASM, mediante PGlite 0.5.10**, conectado a Laravel a través de PDO/pgsql y un adaptador de protocolo local. No se utilizó SQLite como sustituto de PostgreSQL.

El entorno de ejecución no permite crear el usuario de sistema necesario para levantar el servicio PostgreSQL nativo. Por eso se usó el motor WASM. Su adaptador de protocolo no equivale a un servidor nativo ni acredita concurrencia, durabilidad operativa, rendimiento o disponibilidad.

## Cuatro pruebas pendientes en PostgreSQL nativo

La suite entregada contiene **29 casos en total**. Veinticinco pasan aquí. Otros cuatro del grupo native-client provocan errores SQL deliberados y requieren comprobar la recuperación de la conexión PDO después de ellos. El adaptador WASM presentó errores de recuperación/transacción en esos casos, por lo que **no se declaran aprobados**:

1. Fallo al insertar los metadatos de un documento y reversión/limpieza posterior.
2. Rechazo de una modificación del historial.
3. Rechazo de un rol asignado al ámbito incorrecto.
4. Rechazo de una solicitud jurídica incompleta al finalizar la transacción.

Los tres constraints de los puntos 2–4 sí se comprobaron directamente contra el motor SQL, como prueba distinta. El fallo de almacenamiento sin error SQL también está cubierto por una de las 25 pruebas HTTP aprobadas. Esto no reemplaza los cuatro casos de integración pendientes.

En PostgreSQL 18 nativo debe ejecutarse **composer test sin excluir grupos**. Los atributos de grupo permiten identificar las limitaciones del banco de pruebas; no desactivan los casos en la ejecución normal.

## Otras comprobaciones pendientes

- Construcción y ejecución de Dockerfile.dev / compose.dev.yaml: Docker no está disponible aquí. Solo se revisó la configuración estáticamente.
- Conexión y permisos de un bucket S3 real: no se usaron credenciales ni se subieron archivos.
- Autenticación de extremo a extremo: las pruebas usan identidades ficticias con Sanctum::actingAs. Login y recuperación aún no están implementados.
- React, navegador, resolución institucional, calendario de 30 días hábiles, cargas concurrentes y piloto.
- Integración con las tablas y el proyecto reales del equipo. La base incluida es nueva y sus contratos heredados son mínimos.

Los logs y resultados entregados en evidencia corresponden a los casos aprobados y al ciclo de migraciones. No se modificó Drive, no se publicó el proyecto y no se enviaron mensajes ni invitaciones.
