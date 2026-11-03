import assert from 'node:assert/strict'
import test from 'node:test'
import { ApiError } from '../src/lib/api.ts'
import { crearSolicitud, enviarSolicitud, leerSolicitud, renovarPatrocinadores, retomarSolicitud, validarCorreos } from '../src/lib/patrocinios.ts'
import type { DatosJuridica, DatosNatural } from '../src/postulacion/postulacionContext.ts'

const codigo = 'a'.repeat(64)
const natural: DatosNatural = { nombres: 'Persona', apellidos: 'Ficticia', edad: '30', identificacion: 'PRUEBA', correo: 'POSTULANTE@example.test', region: 'La Araucanía', comuna: 'Temuco', actividad: 'Investigación en IA.' }
const juridica: DatosJuridica = { razonSocial: 'Empresa ficticia', rutOrganizacion: 'PRUEBA-EMPRESA', representante: 'Persona Ficticia', rutRepresentante: 'PRUEBA-REP', correo: 'empresa@example.test', region: 'La Araucanía', comuna: 'Temuco', actividad: 'IA aplicada.' }
function cuerpo() {
  return { data: { id: '00000000-0000-4000-8000-000000000001', reference: 'CCHIA-PRE-' + '0'.repeat(26), access_token: codigo,
    legal: 'natural', created_at: '2026-10-29T12:00:00Z', stage: 'waiting_sponsors', ready_for_documents: false,
    payload: { email: natural.correo, givenNames: 'Persona', familyNames: 'Ficticia', age: 30, identifier: 'PRUEBA', region: natural.region, commune: natural.comuna, aiActivity: natural.actividad },
    invitations: [{ position: 1, email: 'uno@example.test', state: 'pending', decided_at: null }, { position: 2, email: 'dos@example.test', state: 'pending', decided_at: null }], application: null } }
}

test('los correos de los dos socios son distintos entre sí y del postulante', () => {
  assert.ok(validarCorreos([' UNO@example.test ', 'uno@example.test'], 'otro@example.test'))
  assert.ok(validarCorreos(['uno@example.test', 'dos@example.test'], 'UNO@example.test'))
  assert.ok(validarCorreos(['incorrecto', 'dos@example.test'], 'otro@example.test'))
  assert.equal(validarCorreos(['uno@example.test', 'dos@example.test'], 'otro@example.test'), null)
})

test('la creación solicita consentimiento sin documentos ni identificadores internos de socios', async t => {
  const original = globalThis.fetch
  t.after(() => { globalThis.fetch = original })
  globalThis.fetch = async (ruta, opciones) => {
    assert.equal(ruta, '/api/v1/intakes')
    assert.equal(opciones?.credentials, 'omit')
    assert.equal(new Headers(opciones?.headers).get('Idempotency-Key'), 'clave-prueba-00000000000000000000')
    const data = JSON.parse(String(opciones?.body))
    assert.deepEqual(data.sponsorEmails, ['uno@example.test', 'dos@example.test'])
    assert.equal(data.sharing_accepted, true)
    assert.equal(data.age, 30)
    assert.equal(data.documents, undefined)
    assert.equal(data.sponsors, undefined)
    return Response.json(cuerpo(), { status: 201 })
  }
  assert.equal((await crearSolicitud(natural, null, [' UNO@example.test ', 'dos@example.test'], 'clave-prueba-00000000000000000000')).codigo, codigo)
})

test('la persona jurídica inicia con datos de organización y representante sin pedir adjuntos', async t => {
  const original = globalThis.fetch
  t.after(() => { globalThis.fetch = original })
  globalThis.fetch = async (_ruta, opciones) => {
    const data = JSON.parse(String(opciones?.body))
    assert.equal(data.legal, 'juridica')
    assert.equal(data.representativeName, juridica.representante)
    assert.equal(data.representativeId, juridica.rutRepresentante)
    assert.equal(data.documents, undefined)
    assert.equal(data.givenNames, undefined)
    const response = cuerpo()
    response.data.legal = 'juridica'
    Object.assign(response.data.payload, { organizationName: juridica.razonSocial, organizationId: juridica.rutOrganizacion, representativeName: juridica.representante, representativeId: juridica.rutRepresentante })
    return Response.json(response, { status: 201 })
  }
  assert.equal((await crearSolicitud(null, juridica, ['uno@example.test', 'dos@example.test'], 'clave-prueba-00000000000000000000')).legal, 'juridica')
})

test('el código privado viaja en el cuerpo de un POST y nunca en la dirección', async t => {
  const original = globalThis.fetch
  t.after(() => { globalThis.fetch = original })
  globalThis.fetch = async (ruta, opciones) => {
    assert.equal(ruta, '/api/v1/intakes/resume')
    assert.equal(opciones?.method, 'POST')
    assert.equal(opciones?.credentials, 'omit')
    assert.equal(JSON.parse(String(opciones?.body)).access_token, codigo)
    return Response.json(cuerpo())
  }
  assert.equal((await retomarSolicitud(cuerpo().data.reference, codigo)).codigo, codigo)
})

test('la renovación conserva la misma clave al recuperar una respuesta perdida', async t => {
  const original = globalThis.fetch
  t.after(() => { globalThis.fetch = original })
  let llamadas = 0
  globalThis.fetch = async (_ruta, opciones) => {
    llamadas++
    assert.equal(new Headers(opciones?.headers).get('Idempotency-Key'), 'renovacion-0000000000000000000000')
    assert.equal(JSON.parse(String(opciones?.body)).confirmation, true)
    if (llamadas === 1) throw new TypeError('Red simulada')
    return Response.json(cuerpo())
  }
  const previa = leerSolicitud(cuerpo())
  await assert.rejects(renovarPatrocinadores(previa, ['uno@example.test', 'dos@example.test'], 'renovacion-0000000000000000000000'), (e: unknown) => e instanceof ApiError && e.incierto)
  assert.equal((await renovarPatrocinadores(previa, ['uno@example.test', 'dos@example.test'], 'renovacion-0000000000000000000000')).reference, previa.reference)
})

test('el envío jurídico adjunta exactamente los dos documentos y no repite datos personales', async t => {
  const original = globalThis.fetch
  t.after(() => { globalThis.fetch = original })
  globalThis.fetch = async (ruta, opciones) => {
    assert.equal(ruta, '/api/v1/intakes/submit')
    const data = opciones?.body as FormData
    assert.equal(data.get('access_token'), codigo)
    assert.ok(data.get('documents[estatutos]') instanceof File)
    assert.ok(data.get('documents[vigencia]') instanceof File)
    assert.equal(data.get('documents[personeria]'), null)
    assert.equal(data.get('documents[antecedentes]'), null)
    assert.equal(data.get('email'), null)
    return Response.json({ data: { id: '00000000-0000-4000-8000-000000000002', reference: 'CCHIA-PRUEBA', status: 'received', submitted_at: '2026-10-29T12:00:00Z', tracking_token: 'b'.repeat(64), local_pilot: false } }, { status: 201 })
  }
  const previa = { ...leerSolicitud(cuerpo()), legal: 'juridica' as const }
  const pdf = new File(['%PDF-1.4'], 'prueba.pdf', { type: 'application/pdf' })
  assert.equal((await enviarSolicitud(previa, { estatutos: pdf, vigencia: pdf, respaldo: pdf })).prueba, false)
})

test('una respuesta incompleta o una etapa inconsistente no habilita documentos ni inventa acceso', () => {
  const sinCodigo = cuerpo()
  sinCodigo.data.access_token = ''
  assert.throws(() => leerSolicitud(sinCodigo), ApiError)
  const falsaEtapa = cuerpo()
  falsaEtapa.data.stage = 'ready_for_documents'
  assert.throws(() => leerSolicitud(falsaEtapa), ApiError)
  const duplicada = cuerpo()
  duplicada.data.invitations[1].position = 1
  assert.throws(() => leerSolicitud(duplicada), ApiError)
})
