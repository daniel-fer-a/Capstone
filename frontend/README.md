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
- `/postular/natural/datos`, `/postular/natural/antecedentes`, `/postular/natural/revision`: formulario, archivos, revisión y envío.
- `/postular/comprobante`: comprobante que permanece solo en memoria.
- `/seguimiento`: consulta por folio y código enviado mediante POST.
- `/ingresar`, `/recuperar`, `/restablecer`: sesión y recuperación de acceso.
- `/area`: bandeja de lectura e inicio explícito de revisión para una cuenta con permisos efectivos.

La modalidad jurídica todavía no tiene su formulario conectado. El envío institucional natural espera la implementación del canal privado de dos patrocinios. Un piloto local puede habilitarse expresamente en el backend y aparece identificado como prueba. No hay almacenamiento de datos personales, credenciales o códigos de seguimiento en `localStorage` ni parámetros de consulta.

Las marcas provienen del Manual de Normas Gráficas CCHIA, marzo de 2026. Se conservó la marca principal aportada por Fabián y se añadió la variante secundaria oficial para pantallas pequeñas. Colores digitales: azul `#003764` y turquesa `#008996`.

La guía de incorporación y validación del paquete explica las variables, migración, cuentas de pruebas y controles nativos. El envío de postulación no crea una cuenta ni concede membresía.
