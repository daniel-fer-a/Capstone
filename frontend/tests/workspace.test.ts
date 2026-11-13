import test from 'node:test'
import assert from 'node:assert/strict'
import { ApiError } from '../src/lib/api.ts'
import { esComision, esEvento, esInvitacion, esMiembro, esRecurso, esTarea, estadoInvitacion, leerComision, leerInvitacionPrivada, leerOperacion, leerPaginaWorkspace, leerPerfil, leerRespuestaInvitacion, workspaceRequest } from '../src/lib/workspace.ts'

const id = '11111111-1111-4111-8111-111111111111'
const commission = { id, name: 'Comisión ficticia', description: '', status: 'active', version: 1 }
test('El perfil exige versión y elección de privacidad válidas', () => {
  const d = { name: 'Persona', email: 'persona@example.test', biography: '', share_biography: false, version: 0 }
  assert.equal(leerPerfil({ data: d }).share_biography, false)
  for (const invalid of [{ ...d, version: -1 }, { ...d, share_biography: 'true' }, { ...d, biography: undefined }]) assert.throws(() => leerPerfil({ data: invalid }), ApiError)
})
test('La consulta no acredita una comisión con estado o identificador inválidos', () => {
  assert.ok(esComision(commission)); assert.equal(esComision({ ...commission, status: 'approved' }), false); assert.equal(esComision({ ...commission, id: '../otro' }), false)
})
test('La ficha necesita todas las capacidades y una nómina válida', () => {
  const d = { ...commission, can_manage: false, can_manage_commission: false, can_invite_members: false, can_invite_leaders: false, members_truncated: false, members: [] }
  assert.equal(leerComision({ data: d }).members.length, 0)
  assert.throws(() => leerComision({ data: { ...d, can_invite_leaders: 'true' } }), ApiError)
  assert.throws(() => leerComision({ data: { ...d, members: [{}] } }), ApiError)
})
test('La nómina de colaboración no acepta roles institucionales', () => {
  const m = { id, user_id: id, role_code: 'miembro_comision', name: 'Persona', biography: null, starts_at: '2026-11-03T00:00:00Z', ends_at: null }
  assert.ok(esMiembro(m)); assert.equal(esMiembro({ ...m, role_code: 'directorio' }), false)
})
test('Las tareas validan responsable y versión antes de mostrar controles', () => {
  const t = { id, title: 'Tarea', description: '', status: 'todo', priority: 'normal', assignee_user_id: null, assignee_name: null, due_date: null, version: 1 }
  assert.ok(esTarea(t)); assert.equal(esTarea({ ...t, assignee_user_id: 'otro' }), false); assert.equal(esTarea({ ...t, status: 'unknown' }), false); assert.equal(esTarea({ ...t, version: 0 }), false)
})
test('Los recursos rechazan tamaños y tipos fuera del contrato privado', () => {
  const r = { id, title: 'Recurso', original_name: 'prueba.pdf', mime_type: 'application/pdf', byte_size: 50, created_at: '2026-11-03T00:00:00Z' }
  assert.ok(esRecurso(r)); assert.equal(esRecurso({ ...r, byte_size: 5242881 }), false); assert.equal(esRecurso({ ...r, mime_type: 'text/html' }), false)
})
test('No se confirma una operación con comprobante incompleto', () => {
  assert.equal(leerOperacion({ data: { id, replayed: true } }).replayed, true)
  assert.throws(() => leerOperacion({ data: { id } }), (e: unknown) => e instanceof ApiError && e.incierto)
})
test('Las páginas necesitan datos validados y límites positivos', () => {
  assert.equal(leerPaginaWorkspace({ data: [commission], current_page: 1, last_page: 2 }, esComision).last, 2)
  assert.throws(() => leerPaginaWorkspace({ data: [commission], current_page: 0, last_page: 2 }, esComision), ApiError)
  assert.throws(() => leerPaginaWorkspace({ data: [{}], current_page: 1, last_page: 2 }, esComision), ApiError)
})
test('La invitación privada no permite confirmar un rol o vigencia malformados', () => {
  const d = { status: 'pending', id, email: 'persona@example.test', role_code: 'miembro_comision', commission_name: 'Prueba', expires_at: '2026-11-04T00:00:00Z', ends_at: null }
  assert.equal(leerInvitacionPrivada({ data: d }).status, 'pending'); assert.deepEqual(leerInvitacionPrivada({ data: { status: 'accepted' } }), { status: 'accepted' })
  assert.throws(() => leerInvitacionPrivada({ data: { ...d, role_code: 'socio' } }), ApiError)
  assert.equal(leerRespuestaInvitacion({ data: { status: 'accepted', replayed: true } }), 'accepted')
  assert.throws(() => leerRespuestaInvitacion({ data: { status: 'accepted' } }), ApiError)
})
test('El vencimiento se muestra aunque el estado almacenado siga pendiente', () => {
  const i = { id, invitee_email: 'persona@example.test', role_code: 'miembro_comision', status: 'pending', expires_at: '2026-11-03T00:00:00Z', assignment_ends_at: null, appointment_reference: null }
  assert.ok(esInvitacion(i)); assert.equal(estadoInvitacion(i, Date.parse('2026-11-04T00:00:00Z')), 'Vencida')
  assert.ok(esEvento({ id, action: 'invitation.declined_by_recipient', actor_name: null, occurred_at: '2026-11-03T00:00:00Z' }))
})
test('El cliente manda el código por POST con CSRF y nunca en URL', async () => {
  const oldFetch = globalThis.fetch, descriptor = Object.getOwnPropertyDescriptor(globalThis, 'document'), seen: { url: string; init?: RequestInit }[] = []
  Object.defineProperty(globalThis, 'document', { configurable: true, value: { cookie: 'XSRF-TOKEN=ficticio' } })
  globalThis.fetch = async (url, init) => { seen.push({ url: String(url), init }); return String(url) === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : Response.json({ data: { status: 'accepted', replayed: false } }) }
  try {
    const code = 'A'.repeat(43); await workspaceRequest('/api/v1/workspace/invitations/accept', { invitation_id: id, code, confirmation: true })
    assert.equal(seen.length, 2); assert.ok(seen.every(c => !c.url.includes(code))); assert.equal(seen[1].init?.method, 'POST'); assert.equal((seen[1].init?.headers as Record<string, string>)['X-XSRF-TOKEN'], 'ficticio'); assert.ok(String(seen[1].init?.body).includes(code))
  } finally { globalThis.fetch = oldFetch; if (descriptor) Object.defineProperty(globalThis, 'document', descriptor); else Reflect.deleteProperty(globalThis, 'document') }
})
test('Un error de servidor en escritura conserva la incertidumbre para recuperar', async () => {
  const oldFetch = globalThis.fetch, descriptor = Object.getOwnPropertyDescriptor(globalThis, 'document')
  Object.defineProperty(globalThis, 'document', { configurable: true, value: { cookie: 'XSRF-TOKEN=ficticio' } })
  globalThis.fetch = async url => String(url) === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 }) : Response.json({ message: 'detalle interno que no se muestra' }, { status: 500 })
  try { await assert.rejects(workspaceRequest('/api/v1/workspace/commissions', { name: 'Prueba' }), (e: unknown) => e instanceof ApiError && e.incierto && !e.message.includes('detalle interno')) }
  finally { globalThis.fetch = oldFetch; if (descriptor) Object.defineProperty(globalThis, 'document', descriptor); else Reflect.deleteProperty(globalThis, 'document') }
})
