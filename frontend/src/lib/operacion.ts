import { ApiError, esObjeto } from './api.ts'

export type Ingreso = { id: string; register_reference: string; legal: 'natural' | 'juridica'; effective_date: string; recorded_at: string; natural_name: string | null; organization_name: string | null }
export type DetalleIngreso = Ingreso & { application_reference: string; natural_identifier: string | null; organization_identifier: string | null; representative_name: string | null; representative_identifier: string | null; email: string; address: string; region: string | null; commune: string | null; ai_activity: string }
export type AvisoCorreo = { id: string; kind: string; attempts: number; created_at: string; available_at: string; sent_at: string | null; abandoned_at: string | null; failed: boolean; can_retry: boolean }

const uuid = (v: unknown) => typeof v === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(v)
export function leerPagina<T>(cuerpo: unknown, valida: (v: unknown) => v is T): { rows: T[]; last: number } {
  if (!esObjeto(cuerpo) || !Array.isArray(cuerpo.data) || !cuerpo.data.every(valida) || typeof cuerpo.last_page !== 'number' || !Number.isInteger(cuerpo.last_page) || cuerpo.last_page < 1) throw new ApiError(502, 'No pudimos comprobar la lista recibida.')
  return { rows: cuerpo.data as T[], last: cuerpo.last_page }
}
export function esIngreso(v: unknown): v is Ingreso {
  return esObjeto(v) && uuid(v.id) && typeof v.register_reference === 'string' && ['natural','juridica'].includes(String(v.legal))
    && typeof v.effective_date === 'string' && typeof v.recorded_at === 'string'
    && (typeof v.natural_name === 'string' || v.natural_name === null) && (typeof v.organization_name === 'string' || v.organization_name === null)
}
export function leerDetalleIngreso(cuerpo: unknown): DetalleIngreso {
  const d = esObjeto(cuerpo) ? cuerpo.data : null
  if (!esIngreso(d) || !esObjeto(d) || !['application_reference','email','address','ai_activity'].every(k => typeof (d as unknown as Record<string,unknown>)[k] === 'string')
    || !['natural_identifier','organization_identifier','representative_name','representative_identifier','region','commune'].every(k => typeof (d as unknown as Record<string,unknown>)[k] === 'string' || (d as unknown as Record<string,unknown>)[k] === null)) throw new ApiError(502, 'No pudimos comprobar la ficha del ingreso.')
  return d as DetalleIngreso
}
export function esAvisoCorreo(v: unknown): v is AvisoCorreo {
  return esObjeto(v) && uuid(v.id) && ['sponsor_invitation','application_received','application_event'].includes(String(v.kind))
    && typeof v.attempts === 'number' && Number.isInteger(v.attempts) && v.attempts >= 0 && v.attempts <= 5
    && typeof v.created_at === 'string' && typeof v.available_at === 'string'
    && (v.sent_at === null || typeof v.sent_at === 'string') && (v.abandoned_at === null || typeof v.abandoned_at === 'string')
    && typeof v.failed === 'boolean' && typeof v.can_retry === 'boolean'
}
export function nombreEstadoAviso(a: AvisoCorreo): string {
  return a.sent_at ? 'Procesado por el transporte' : a.abandoned_at ? a.failed ? 'Intentos agotados por error' : 'Omitido por vigencia o estado' : 'Pendiente o en reintento'
}
