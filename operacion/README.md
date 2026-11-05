# Operación y continuidad

Consolidado el 3 de noviembre de 2026. El código de operación acompaña al incremento de postulaciones; no publica servicios ni modifica el Compose de desarrollo recibido.

## Validar la entrega

Desde `proyecto`, instalar las dependencias del frontend con `npm ci` en su carpeta y ejecutar con Node 24:

```bash
node operacion/frontend_evidence.mjs
```

Se verifica `CODIGO_SHA256.json` y se registran 24 pruebas, lint y build dentro de `private/evidencias`. La validación completa del backend debe producir 118 casos en PostgreSQL 18 nativo sobre una base desechable cuyo nombre termine en `_test`. Seguir la guía del paquete; no ejecutar pruebas contra los datos de trabajo.

El evaluador de evidencia recibe una copia completada de `aceptacion.template.json`. Las declaraciones parten pendientes. Revisa coherencia y presencia de resultados; no certifica independientemente una aceptación ni despliega el sistema.

```bash
php operacion/evaluar_evidencias.php /ruta/privada/aceptacion.json
```

## QA separado

Desde `proyecto`, en Bash de WSL o Linux con Docker Compose, OpenSSL y herramientas estándar:

```bash
bash operacion/scripts/preparar_qa.sh --crear
bash operacion/scripts/qa.sh config
bash operacion/scripts/qa.sh construir
bash operacion/scripts/qa.sh iniciar
bash operacion/scripts/qa.sh migrar
bash operacion/scripts/qa.sh probar
```

El proyecto se llama `cchia-qa-isolated`, usa volúmenes propios y publica solo localhost:8088. No expone el puerto de PostgreSQL. Las pruebas usan otra base, `cchia_qa_test`. La plantilla se preparó y se comprobó estáticamente; la construcción de imágenes y el recorrido con Docker real están pendientes.

Habilitar cuentas ficticias según `QA_AISLADO_Y_RECUPERACION.md`. No usar datos reales ni habilitar envíos reales para una restauración de prueba. El scheduler comienza solo por una acción expresa:

```bash
bash operacion/scripts/qa.sh scheduler
bash operacion/scripts/qa.sh estado
```

## Respaldo y recuperación de esta plantilla

```bash
bash operacion/scripts/respaldo_qa.sh --crear
bash operacion/scripts/verificar_respaldo.sh ID_RESPALDO
bash operacion/scripts/restaurar_qa.sh ID_RESPALDO --aplicar
```

Estos scripts solo corresponden al QA separado. El respaldo contiene datos, documentos y secretos: mantenerlo privado. Restaurar utiliza otro proyecto y puerto 8089, rechaza un destino existente y deja detenido el scheduler. No se ha probado una recuperación con Docker real. Conservar además el código que corresponde a cada respaldo.

Los manuales detallados, límites y lista de validación están en `documentacion` en la raíz del paquete. No subir `private`, `.env`, dumps ni información real al repositorio o al chat.
