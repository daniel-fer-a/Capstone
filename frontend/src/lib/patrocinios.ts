import { ApiError, esObjeto, leerComprobante, solicitudJson } from './api.ts'
import type { Comprobante, DatosJuridica, DatosNatural, SolicitudPrevia } from '../postulacion/postulacionContext'

export function leerSolicitud(cuerpo: unknown, codigo?: string): SolicitudPrevia {
  const d = esObjeto(cuerpo) && esObjeto(cuerpo.data) ? cuerpo.data : null
  const secreto = codigo ?? d?.access_token
  if (!d || typeof d.id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(d.id)
    || typeof d.reference !== 'string' || !/^CCHIA-PRE-[A-Z0-9]{26}$/.test(d.reference)
    || typeof secreto !== 'string' || !/^[A-Za-z0-9]{64}$/.test(secreto)
    || !['natural', 'juridica'].includes(String(d.legal)) || !['waiting_sponsors', 'ready_for_documents', 'submitted'].includes(String(d.stage))
    || typeof d.ready_for_documents !== 'boolean' || typeof d.created_at !== 'string' || !Number.isFinite(Date.parse(d.created_at))
    || !esObjeto(d.payload) || !Array.isArray(d.invitations) || d.invitations.length !== 2
    || !d.invitations.every(i => esObjeto(i) && typeof i.email === 'string' && typeof i.position === 'number' && [1, 2].includes(i.position)
      && ['pending', 'accepted', 'declined', 'confirmation_unavailable'].includes(String(i.state))
      && (i.decided_at === null || (typeof i.decided_at === 'string' && Number.isFinite(Date.parse(i.decided_at)))))
    || new Set(d.invitations.map(i => (i as Record<string, unknown>).position)).size !== 2) {
    throw new ApiError(502, 'No pudimos confirmar los datos de la solicitud. Reintenta la misma operación.', {}, undefined, true)
  }
  for (const key of ['email', 'region', 'commune', 'aiActivity', ...(d.legal === 'natural'
    ? ['givenNames', 'familyNames', 'identifier'] : ['organizationName', 'organizationId', 'representativeName', 'representativeId'])]) {
    if (typeof d.payload[key] !== 'string') throw new ApiError(502, 'No pudimos leer los antecedentes de la solicitud.')
  }
  if (d.legal === 'natural' && (!Number.isInteger(d.payload.age) || Number(d.payload.age) < 0)) throw new ApiError(502, 'No pudimos leer los antecedentes de la solicitud.')
  if (d.application !== null && (!esObjeto(d.application) || typeof d.application.reference !== 'string' || !/^CCHIA-[A-Z0-9-]+$/.test(d.application.reference)
    || !['received', 'under_review', 'approved', 'rejected'].includes(String(d.application.status))
    || typeof d.application.submitted_at !== 'string' || !Number.isFinite(Date.parse(d.application.submitted_at)))) throw new ApiError(502, 'No pudimos leer el estado de la postulación.')
  if ((d.stage === 'submitted') !== (d.application !== null) || (d.stage === 'waiting_sponsors') === d.ready_for_documents) throw new ApiError(502, 'No pudimos verificar la etapa de la solicitud.')
  return { ...d, codigo: secreto } as SolicitudPrevia
}

export async function crearSolicitud(natural: DatosNatural | null, juridica: DatosJuridica | null, emails: string[], clave: string): Promise<SolicitudPrevia> {
  const comun = juridica ?? natural
  if (!comun) throw new ApiError(422, 'Completa tus datos antes de solicitar los patrocinios.')
  const cuerpo = {
    membership: 'member', legal: juridica ? 'juridica' : 'natural', email: comun.correo.trim(),
    region: comun.region.trim(), commune: comun.comuna.trim(), aiActivity: comun.actividad.trim(),
    ...(juridica ? { organizationName: juridica.razonSocial.trim(), organizationId: juridica.rutOrganizacion.trim(),
      representativeName: juridica.representante.trim(), representativeId: juridica.rutRepresentante.trim() }
      : { givenNames: natural!.nombres.trim(), familyNames: natural!.apellidos.trim(), age: Number(natural!.edad), identifier: natural!.identificacion.trim() }),
    sponsorEmails: emails.map(e => e.trim().toLowerCase()), declaration_accepted: true, sharing_accepted: true,
  }
  return leerSolicitud(await solicitudJson('/api/v1/intakes', {
    method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': clave }, body: JSON.stringify(cuerpo),
  }, 201))
}

export async function retomarSolicitud(reference: string, codigo: string): Promise<SolicitudPrevia> {
  return leerSolicitud(await solicitudJson('/api/v1/intakes/resume', {
    method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ reference: reference.trim(), access_token: codigo.trim() }),
  }), codigo.trim())
}

export async function renovarPatrocinadores(previa: SolicitudPrevia, emails: string[], clave: string): Promise<SolicitudPrevia> {
  return leerSolicitud(await solicitudJson('/api/v1/intakes/sponsors', {
    method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': clave },
    body: JSON.stringify({ reference: previa.reference, access_token: previa.codigo, sponsorEmails: emails.map(e => e.trim().toLowerCase()), confirmation: true }),
  }), previa.codigo)
}

export async function enviarSolicitud(previa: SolicitudPrevia, archivos: { estatutos: File | null; vigencia: File | null; respaldo: File | null }): Promise<Comprobante> {
  const form = new FormData()
  form.set('reference', previa.reference)
  form.set('access_token', previa.codigo)
  form.set('declaration_accepted', '1')
  if (previa.legal === 'juridica') {
    if (archivos.estatutos) form.set('documents[estatutos]', archivos.estatutos)
    if (archivos.vigencia) form.set('documents[vigencia]', archivos.vigencia)
  } else if (archivos.respaldo) form.set('documents[antecedentes]', archivos.respaldo)
  return leerComprobante(await solicitudJson('/api/v1/intakes/submit', { method: 'POST', credentials: 'omit', body: form }, 201))
}

export function validarCorreos(emails: string[], propio: string): string | null {
  const normalizados = emails.map(e => e.trim().toLowerCase())
  if (normalizados.length !== 2 || normalizados.some(e => e.length > 255 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e))) return 'Indica dos correos electrónicos válidos.'
  if (normalizados[0] === normalizados[1]) return 'Los dos socios deben ser distintos.'
  if (normalizados.includes(propio.trim().toLowerCase())) return 'Los correos de los socios deben ser diferentes del tuyo.'
  return null
}
