# Guía de instalación y comprobación

Esta entrega utiliza Laravel 13, PHP 8.4.x y PostgreSQL **18.x**. Usar una carpeta y una base NUEVAS de desarrollo. No copiar las migraciones sobre la base compartida que ya estén usando.

## Opción A: PHP y PostgreSQL instalados localmente

1. Abrir una terminal en la carpeta backend. Comprobar `php -v`, `composer --version` y `psql --version`. PHP debe disponer de pdo_pgsql, fileinfo, mbstring, dom, xml y las extensiones que comprueba Composer.
2. Crear bases vacías separadas cchia_dev y cchia_test en PostgreSQL 18, con una cuenta local que pueda crear sus tablas y la extensión pgcrypto.
3. Copiar .env.example a .env y .env.testing.example a .env.testing. Completar host, puerto, cuenta y contraseña locales. Nunca mandar esos archivos con credenciales al chat o al repositorio.
4. Instalar las dependencias EXACTAS del lockfile y preparar la clave de aplicación:

```sh
composer install
composer check-platform-reqs
php artisan key:generate
php artisan config:clear
php artisan admissions:diagnose
php artisan migrate
php artisan admissions:diagnose
php artisan serve --host=127.0.0.1 --port=8000
```

En PowerShell, las copias se realizan así:

```powershell
Copy-Item .env.example .env
Copy-Item .env.testing.example .env.testing
```

En Linux/macOS se puede usar cp. No ejecutar composer update por rutina: cambia las dependencias fijadas. Las actualizaciones se resuelven, revisan y prueban como una tarea separada.

Para archivos en PHP local, ajustar upload_max_filesize=5M, post_max_size=24M y max_file_uploads=4. El archivo docker/uploads.ini contiene estos valores para el perfil Docker.

## Primera prueba de registro

Con el servidor local iniciado, ejecutar en otra terminal, dentro de backend:

```sh
curl -i -X POST http://127.0.0.1:8000/api/v1/applications -H "Accept: application/json" -H "Content-Type: application/json" --data-binary @examples/member-natural.json
```

En Windows PowerShell usar curl.exe si curl es un alias de Invoke-WebRequest. Se espera HTTP 201 con id, reference, status=received, submitted_at y tracking_token. Conservar el código devuelto: solo se guarda su hash y el endpoint público de consulta se implementará después.

Comprobar en la base local:

```sql
SELECT reference, membership, legal, status, submitted_at
FROM cchia_admissions.applications
ORDER BY submitted_at DESC;

SELECT action, outcome, occurred_at
FROM cchia_admissions.application_events
ORDER BY occurred_at DESC;
```

Una postulación jurídica necesita dos usuarios con asignaciones socio global activas y vigentes en el Modelo 02. No existe un catálogo público de patrocinadores en esta base. Los fixtures de las pruebas muestran cómo construir datos ficticios; no se deben convertir en el registro real de socios.

## Pruebas en PostgreSQL nativo

Completar primero .env.testing, especialmente DB_DATABASE=cchia_test. La base debe ser distinta a la de desarrollo y su nombre debe terminar en _test. No usar una base con datos reales.

```sh
php artisan migrate --env=testing
composer test
composer audit
```

La suite completa incluye las pruebas del grupo native-client, que quedaron pendientes en este entorno. No excluirlas en la validación nativa. El detalle de lo que sí se ejecutó está en VERIFICACION.md.

## Opción B: perfil Docker de desarrollo

El paquete incluye Dockerfile.dev y compose.dev.yaml. Este perfil no se construyó ni ejecutó aquí porque Docker no está disponible. Tiene PostgreSQL 18 y una API de desarrollo local; no incluye Nginx/TLS, Redis ni Reverb.

1. Copiar .env.example a .env y completar DB_PASSWORD con una contraseña local. Mantener DB_DATABASE=cchia_dev y DB_USERNAME=cchia_dev, o adaptar los valores coherentemente.
2. Construir y preparar los contenedores:

```sh
docker compose -f compose.dev.yaml build
docker compose -f compose.dev.yaml up -d db
docker compose -f compose.dev.yaml run --rm api php artisan key:generate --show
```

Copiar manualmente el valor generado a APP_KEY de .env. Luego:

```sh
docker compose -f compose.dev.yaml run --rm api php artisan migrate
docker compose -f compose.dev.yaml up -d api
docker compose -f compose.dev.yaml exec api php artisan admissions:diagnose
```

El init del volumen nuevo crea cchia_test. Para ejecutar las pruebas con esa base:

```sh
docker compose -f compose.dev.yaml run --rm -e APP_ENV=testing -e DB_DATABASE=cchia_test api php artisan migrate
docker compose -f compose.dev.yaml run --rm -e APP_ENV=testing -e DB_DATABASE=cchia_test api php artisan test
```

Los puertos publicados se limitan a 127.0.0.1. PostgreSQL 18 usa un volumen en /var/lib/postgresql, conforme al diseño actual de la imagen oficial. El script de inicialización solo se ejecuta en un volumen nuevo; esta guía no elimina ni reutiliza un volumen anterior.

## Almacenamiento y revisión

Por defecto ADMISSIONS_STORAGE_DRIVER=local guarda los archivos fuera de public. No ejecutar storage:link para los antecedentes. El mismo disco lógico admite S3 configurando ADMISSIONS_STORAGE_DRIVER=s3 y las variables AWS del ejemplo. El bucket, sus permisos y su conectividad necesitan una comprobación en el entorno autorizado; aquí no se hicieron transferencias a S3.

Mantener ADMISSIONS_REVIEW_ENABLED=false hasta definir la política sensible. Cambiarlo a true no crea usuarios, roles ni permisos: las rutas requieren además Sanctum, una asignación Directorio global efectiva y los permisos explícitos admissions.read y admissions.documents.read. El inicio de sesión aún no está implementado.

## Integración con el proyecto

Esta base eligió varchar(36) para las claves heredadas del Modelo 02. Si su base existente usa UUID en users.id, no basta con copiar estas migraciones. Primero comparar el esquema y los datos reales. Los contratos mínimos companies/commissions/resources deben reemplazarse por una integración revisada con el proyecto completo.

No se incluyen comandos migrate:fresh, reset, drop database ni eliminación de volúmenes como instrucciones de instalación.
