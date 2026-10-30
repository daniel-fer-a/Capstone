import { ApiError, esObjeto, solicitudJson } from './api.ts'

export type Sesion = { id: string; name: string; email: string; can_review: boolean; can_start_review: boolean }

async function csrf(): Promise<string> {
  const respuesta = await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
  if (!respuesta.ok) throw new ApiError(respuesta.status, 'No pudimos iniciar la conexión segura. Inténtalo nuevamente.')
  const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))
  if (!cookie) throw new ApiError(419, 'El navegador no permitió iniciar la sesión de seguridad.')
  return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
}

export async function peticionPrivada(ruta: string, datos?: unknown): Promise<unknown> {
  return solicitudJson(ruta, datos === undefined
    ? { credentials: 'same-origin' }
    : {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': await csrf() },
        body: JSON.stringify(datos),
      })
}

export function leerSesion(cuerpo: unknown): Sesion {
  const d = esObjeto(cuerpo) && esObjeto(cuerpo.data) ? cuerpo.data : null
  if (!d || typeof d.id !== 'string' || typeof d.name !== 'string' || typeof d.email !== 'string'
    || typeof d.can_review !== 'boolean' || typeof d.can_start_review !== 'boolean') {
    throw new ApiError(502, 'No pudimos verificar la cuenta. Inténtalo nuevamente.')
  }
  return d as Sesion
}
