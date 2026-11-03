# Frontend CCHIA

Interfaz de ingreso de socios, consulta de estado y acceso institucional. React 19, TypeScript 6, Vite 8, Tailwind 4, React Router y React Hook Form. Usar Node 24 y conservar `package-lock.json`.

## Desarrollo

Desde esta carpeta:

```bash
npm ci
npm run dev
```

El proxy fuera de Docker apunta a `http://127.0.0.1:8000`. Dentro del Compose recibido desde `backend/` se configura `API_PROXY_TARGET=http://api:8000`. Se reenvían `/api` y `/sanctum`.

```bash
npm test
npm run lint
npm run build
```

## Recorridos

- `/postular`: selección de modalidad.
- `/postular/natural/datos`: datos de persona natural.
- `/postular/juridica`: datos de organización y representante.
- `/postular/patrocinios`: solicitud de dos patrocinios y consulta de confirmaciones.
- `/continuar`: retomar la etapa con folio y código privados.
- `/postular/documentos`: archivos habilitados después de los dos patrocinios.
- `/postular/revision`: resumen y envío formal, con recuperación frente a respuestas perdidas.
- `/postular/comprobante`: comprobante que permanece solo en memoria.
- `/seguimiento`: consulta por folio y código enviado mediante POST.
- `/ingresar`, `/recuperar`, `/restablecer`: sesión y recuperación de acceso.
- `/area/patrocinios`: aceptación o rechazo de invitaciones propias de un socio habilitado.
- `/area`: bandeja, descargas privadas, inicio explícito de revisión y registro del acuerdo inicial del Directorio, según permisos efectivos.

Ambas modalidades de socio requieren confirmaciones explícitas de dos socios distintos y vigentes. Después se habilitan los documentos. Para persona jurídica se solicitan exactamente estatutos y certificado de vigencia, además del nombre completo y RUT del representante registrados en los datos.

El folio `CCHIA-PRE-…` y su código permiten retomar el proceso sin crear una cuenta de postulante. El folio formal y código de seguimiento se obtienen al completar el envío. Los accesos se mantienen en memoria y se pueden descargar en privado. No se guardan datos personales, credenciales o códigos en `localStorage`, `sessionStorage` ni parámetros de consulta.

Las rutas naturales anteriores de antecedentes y revisión se conservan para el piloto local identificado como prueba. El recorrido principal utiliza las nuevas etapas y no permite acreditar patrocinios mediante IDs sin aceptación.

Los avisos de invitación y recepción se procesan en el backend. La interfaz no afirma que un correo fue entregado por registrar una solicitud. La configuración y comprobación de SMTP se realizan en el entorno local correspondiente.

Las marcas provienen del Manual de Normas Gráficas CCHIA, marzo de 2026. Se conservó la marca principal aportada por Fabián y se añadió la variante secundaria oficial para pantallas pequeñas. Colores digitales: azul `#003764` y turquesa `#008996`.

La guía de incorporación y validación del paquete explica las variables, migración, cuentas de pruebas y controles nativos. El envío de postulación no crea una cuenta ni concede membresía.
