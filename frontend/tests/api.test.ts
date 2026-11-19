import test, { afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { ApiError, enviarPostulacionNatural, esperaReintento, solicitudJson } from '../src/lib/api.ts'

const original = globalThis.fetch
const datos = { nombres: 'Persona', apellidos: 'de prueba', edad: '30', identificacion: 'Prueba', correo: 'prueba@example.test', region: 'La Araucanía', comuna: 'Temuco', actividad: 'Investigación en IA.' }
const respuesta = { data: { id: '00000000-0000-4000-8000-000000000001', reference: 'CCHIA-PRUEBA', status: 'received', submitted_at: '2026-10-28T10:00:00Z', tracking_token: 'a'.repeat(64), local_pilot: true } }
afterEach(() => { globalThis.fetch = original })

test('envía campos separados y la misma clave de recuperación sin cookies', async () => {
  let opciones: RequestInit | undefined
  globalThis.fetch = async (_, init) => { opciones = init; return Response.json(respuesta, { status: 201 }) }
  const recibido = await enviarPostulacionNatural(datos, null, 'clave-constante-de-envio-con-36-caracteres')
  const form = opciones?.body as FormData
  assert.equal(form.get('givenNames'), datos.nombres)
  assert.equal(form.get('familyNames'), datos.apellidos)
  assert.equal(form.get('age'), '30')
  assert.equal(form.get('region'), datos.region)
  assert.equal(form.get('commune'), datos.comuna)
  assert.equal(form.get('sponsors'), null)
  assert.equal(opciones?.credentials, 'omit')
  assert.equal((opciones?.headers as Record<string,string>)['Idempotency-Key'], 'clave-constante-de-envio-con-36-caracteres')
  assert.equal(recibido.folio, 'CCHIA-PRUEBA')
  assert.equal(recibido.prueba, true)
})

test('un 201 incompleto no muestra un comprobante ficticio', async () => {
  globalThis.fetch = async () => Response.json({ data: { id: 'incompleto' } }, { status: 201 })
  await assert.rejects(enviarPostulacionNatural(datos, null, 'x'.repeat(36)), (e: unknown) => e instanceof ApiError && e.incierto && e.status === 502)
})

test('mapea edad, ubicación y patrocinio sin mostrar mensajes internos', async () => {
  globalThis.fetch = async () => Response.json({ errors: { age: ['Internal'], commune: ['Internal'], 'sponsors.0': ['Internal'] } }, { status: 422 })
  await assert.rejects(enviarPostulacionNatural(datos, null, 'x'.repeat(36)), (e: unknown) => e instanceof ApiError && !!e.campos.edad && !!e.campos.comuna && !!e.campos.patrocinios && !e.message.includes('Internal'))
})

test('un fallo de red permite recuperar el mismo envío', async () => {
  const claves: string[] = []
  globalThis.fetch = async (_, init) => {
    claves.push((init?.headers as Record<string,string>)['Idempotency-Key'])
    if (claves.length === 1) throw new TypeError('Network failed')
    return Response.json(respuesta, { status: 201 })
  }
  await assert.rejects(enviarPostulacionNatural(datos, null, 'x'.repeat(36)), (e: unknown) => e instanceof ApiError && e.incierto)
  const recibido = await enviarPostulacionNatural(datos, null, 'x'.repeat(36))
  assert.equal(recibido.folio, 'CCHIA-PRUEBA')
  assert.deepEqual(claves, ['x'.repeat(36), 'x'.repeat(36)])
})

test('respeta el tiempo de espera de un 429', async () => {
  globalThis.fetch = async () => Response.json({}, { status: 429, headers: { 'Retry-After': '42' } })
  await assert.rejects(solicitudJson('/api/prueba'), (e: unknown) => e instanceof ApiError && e.reintentarEnSegundos === 42)
})

test('interpreta Retry-After en segundos o fecha HTTP', () => {
  assert.equal(esperaReintento('30'), 30)
  assert.equal(esperaReintento('Wed, 28 Oct 2026 10:00:30 GMT', Date.parse('2026-10-28T10:00:00Z')), 30)
  for (const valor of [null, '', '-1', 'texto', '0']) assert.equal(esperaReintento(valor), undefined)
})

test('un 413 y un 409 no se convierten en éxito', async () => {
  for (const status of [413, 409]) {
    globalThis.fetch = async () => Response.json({}, { status })
    await assert.rejects(solicitudJson('/api/prueba'), (e: unknown) => e instanceof ApiError && e.status === status)
  }
})

test('un 200 no sustituye la confirmación 201 de una postulación', async () => {
  globalThis.fetch = async () => Response.json(respuesta, { status: 200 })
  await assert.rejects(enviarPostulacionNatural(datos, null, 'x'.repeat(36)), (e: unknown) => e instanceof ApiError && e.status === 502 && e.incierto)
})

test('no acepta folios vacíos ni omite la identificación del piloto', async () => {
  for (const data of [{ ...respuesta.data, reference: '' }, { ...respuesta.data, local_pilot: undefined }]) {
    globalThis.fetch = async () => Response.json({ data }, { status: 201 })
    await assert.rejects(enviarPostulacionNatural(datos, null, 'x'.repeat(36)), (e: unknown) => e instanceof ApiError && e.status === 502 && e.incierto)
  }
})
