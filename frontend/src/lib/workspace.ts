import { ApiError, esObjeto } from './api.ts'
import { csrf } from './httpPrivado.ts'

export type Perfil = { name: string; email: string; biography: string; share_biography: boolean; version: number }
export type Comision = { id: string; name: string; description: string; status: 'active' | 'archived'; version: number }
export type Miembro = { id: string; user_id: string; role_code: 'lider_comision' | 'miembro_comision'; name: string; biography: string | null; starts_at: string; ends_at: string | null }
export type DetalleComision = Comision & { can_manage: boolean; can_manage_commission: boolean; can_invite_members: boolean; can_invite_leaders: boolean; members: Miembro[]; members_truncated: boolean }
export type Proyecto = Comision
export type Tarea = { id: string; title: string; description: string; status: 'todo' | 'in_progress' | 'done'; priority: 'low' | 'normal' | 'high'; assignee_user_id: string | null; assignee_name: string | null; due_date: string | null; version: number }
export type Recurso = { id: string; title: string; original_name: string; mime_type: string; byte_size: number; created_at: string }
export type Evento = { id: string; action: string; actor_name: string | null; occurred_at: string }
export type Invitacion = { id: string; invitee_email: string; role_code: string; status: string; expires_at: string; assignment_ends_at: string | null; appointment_reference: string | null }
export type InvitacionPrivada = { status: 'pending'; id: string; email: string; role_code: string; commission_name: string; expires_at: string; ends_at: string | null } | { status: 'accepted' | 'declined' }
export type PaginaDatos<T> = { rows: T[]; current: number; last: number }
export const uuid = (v: unknown): v is string => typeof v === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v)
const texto = (v: unknown): v is string => typeof v === 'string'
const nullable = (v: unknown): v is string | null => v === null || texto(v)
const entero = (v: unknown, min = 1): v is number => Number.isSafeInteger(v) && Number(v) >= min
const fecha = (v: unknown): v is string => texto(v) && Number.isFinite(Date.parse(v))
const invalido = (): never => { throw new ApiError(502, 'No pudimos verificar la respuesta. Recarga la consulta antes de continuar.', {}, undefined, true) }
export function datos(c: unknown): Record<string, unknown> { if (!esObjeto(c) || !esObjeto(c.data)) return invalido(); return c.data }
export function leerPerfil(c: unknown): Perfil {
  const d = datos(c)
  if (!texto(d.name) || !texto(d.email) || !texto(d.biography) || typeof d.share_biography !== 'boolean' || !entero(d.version, 0)) return invalido()
  return d as Perfil
}
export function esComision(v: unknown): v is Comision { return esObjeto(v) && uuid(v.id) && texto(v.name) && texto(v.description) && ['active', 'archived'].includes(String(v.status)) && entero(v.version) }
export function esMiembro(v: unknown): v is Miembro { return esObjeto(v) && uuid(v.id) && uuid(v.user_id) && texto(v.name) && nullable(v.biography) && ['lider_comision', 'miembro_comision'].includes(String(v.role_code)) && fecha(v.starts_at) && (v.ends_at === null || fecha(v.ends_at)) }
export function leerComision(c: unknown): DetalleComision {
  const d = datos(c)
  const members: unknown = d.members
  if (!esComision(d) || !['can_manage', 'can_manage_commission', 'can_invite_members', 'can_invite_leaders', 'members_truncated'].every(k => typeof (d as Record<string, unknown>)[k] === 'boolean')
    || !Array.isArray(members) || !members.every(esMiembro)) return invalido()
  return d as DetalleComision
}
export function esTarea(v: unknown): v is Tarea { return esObjeto(v) && uuid(v.id) && texto(v.title) && texto(v.description) && ['todo', 'in_progress', 'done'].includes(String(v.status)) && ['low', 'normal', 'high'].includes(String(v.priority)) && (v.assignee_user_id === null || uuid(v.assignee_user_id)) && nullable(v.assignee_name) && (v.due_date === null || (texto(v.due_date) && /^\d{4}-\d{2}-\d{2}$/.test(v.due_date))) && entero(v.version) }
export function esRecurso(v: unknown): v is Recurso { return esObjeto(v) && uuid(v.id) && texto(v.title) && texto(v.original_name) && ['application/pdf', 'image/jpeg', 'image/png'].includes(String(v.mime_type)) && entero(v.byte_size) && v.byte_size <= 5242880 && fecha(v.created_at) }
export function esEvento(v: unknown): v is Evento { return esObjeto(v) && uuid(v.id) && texto(v.action) && nullable(v.actor_name) && fecha(v.occurred_at) }
export function esInvitacion(v: unknown): v is Invitacion { return esObjeto(v) && uuid(v.id) && texto(v.invitee_email) && ['lider_comision', 'miembro_comision'].includes(String(v.role_code)) && ['pending', 'accepted', 'declined', 'cancelled', 'expired'].includes(String(v.status)) && fecha(v.expires_at) && (v.assignment_ends_at === null || fecha(v.assignment_ends_at)) && nullable(v.appointment_reference) }
export function leerPaginaWorkspace<T>(c: unknown, guard: (v: unknown) => v is T): PaginaDatos<T> {
  if (!esObjeto(c) || !Array.isArray(c.data) || !c.data.every(guard) || !entero(c.current_page) || !entero(c.last_page)) return invalido()
  return { rows: c.data, current: c.current_page, last: c.last_page }
}
export function leerOperacion(c: unknown): Record<string, unknown> {
  const d = datos(c); if (!uuid(d.id) || typeof d.replayed !== 'boolean') return invalido(); return d
}
export function leerInvitacionPrivada(c: unknown): InvitacionPrivada {
  const d = datos(c)
  if (d.status === 'accepted' || d.status === 'declined') return { status: d.status }
  if (d.status !== 'pending' || !uuid(d.id) || !texto(d.email) || !texto(d.commission_name) || !['lider_comision', 'miembro_comision'].includes(String(d.role_code)) || !fecha(d.expires_at) || !(d.ends_at === null || fecha(d.ends_at))) return invalido()
  return d as InvitacionPrivada
}
export function leerRespuestaInvitacion(c: unknown): 'accepted' | 'declined' { const d = datos(c); if (!['accepted', 'declined'].includes(String(d.status)) || typeof d.replayed !== 'boolean') return invalido(); return d.status as 'accepted' | 'declined' }
export const ESTADOS_TAREA: Record<Tarea['status'], string> = { todo: 'Pendiente', in_progress: 'En curso', done: 'Terminada' }
export const rolComision = (r: string) => r === 'lider_comision' ? 'Líder de comisión' : 'Miembro de comisión'
export function estadoInvitacion(i: Invitacion, ahora = Date.now()): string {
  if (i.status === 'pending' && Date.parse(i.expires_at) <= ahora) return 'Vencida'
  return ({ pending: 'Pendiente', accepted: 'Aceptada', declined: 'Rechazada', cancelled: 'Cancelada', expired: 'Vencida' } as Record<string, string>)[i.status] ?? 'Estado desconocido'
}
export async function workspaceRequest(url: string, body?: unknown, key?: string): Promise<unknown> {
  const controller = new AbortController(), timer = globalThis.setTimeout(() => controller.abort(), 60_000)
  try {
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (body !== undefined) { headers['X-XSRF-TOKEN'] = await csrf(); if (!(body instanceof FormData)) headers['Content-Type'] = 'application/json'; if (key) headers['Idempotency-Key'] = key }
    const r = await fetch(url, { credentials: 'same-origin', method: body === undefined ? 'GET' : 'POST', headers, body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body), signal: controller.signal, cache: 'no-store', referrerPolicy: 'same-origin' })
    const c: unknown = await r.json().catch(() => null)
    if (r.ok) { if (!esObjeto(c)) return invalido(); return c }
    let message = ({ 401: 'Inicia sesión para continuar.', 403: 'Tu cuenta no tiene permiso para esta acción.', 404: 'El recurso o la invitación no están disponibles.', 409: 'El registro cambió. Actualiza la consulta antes de continuar.', 419: 'La sesión de seguridad expiró. Reintenta la misma operación.', 429: 'Espera antes de realizar otra operación.', 503: 'El espacio no está disponible en este momento.' } as Record<number, string>)[r.status] ?? 'No pudimos confirmar la operación.'
    if ([400, 403, 404, 409, 422].includes(r.status) && esObjeto(c) && texto(c.message)) message = c.message
    if (r.status === 422 && esObjeto(c) && esObjeto(c.errors)) { const first = Object.values(c.errors).find(v => Array.isArray(v) && typeof v[0] === 'string'); if (Array.isArray(first)) message = first[0] }
    throw new ApiError(r.status, message, {}, undefined, r.status >= 500)
  } catch (e) {
    if (e instanceof ApiError) throw e
    throw new ApiError(0, 'La respuesta se perdió. Recupera la misma operación sin cambiar sus datos.', {}, undefined, true)
  } finally { globalThis.clearTimeout(timer) }
}
