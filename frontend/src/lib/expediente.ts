import { ApiError, esObjeto } from './api.ts'

export type DocumentoVersionado = { id: string; kind: string; version_no: number; original_name: string; bytes: number; uploaded_at: string }
export type Subsanacion = {
  id: string; reason: string; requested_kinds: string[]; requested_at: string
  status: 'waiting_applicant' | 'submitted' | 'accepted' | 'needs_changes'
  submitted_at: string | null; documents: DocumentoVersionado[]
  review: { outcome: string; reason: string; reviewed_at: string } | null
}
export type Gestion = {
  effective_outcome: 'approved' | 'rejected' | null
  appeal: { id: string; reason: string; requested_at: string; status: 'resolved' | 'pending_assembly'; resolution: {
    outcome: string; reason: string; meeting_date: string; meeting_type: string; minutes_reference?: string
  } | null } | null
  corrections: Subsanacion[]
  membership: { effective_date: string; registered_at: string; register_reference?: string } | null
  formalization_enabled: boolean
}
export type CredencialesExpediente = { reference: string; tracking_token: string }
export const NOMBRES_DOCUMENTOS: Record<string, string> = { estatutos: 'Estatutos', vigencia: 'Certificado de vigencia', personeria: 'Personería histórica', antecedentes: 'Respaldo de actividad en IA' }

export function tituloGestion(g: Gestion, estado: string): string | null {
  if (g.membership) return 'Ingreso registrado'
  if (g.appeal?.status === 'pending_assembly') return 'Apelación pendiente de Asamblea'
  if (g.appeal?.resolution) return g.appeal.resolution.outcome === 'approved' ? 'Aprobada por la Asamblea' : 'Rechazo confirmado por la Asamblea'
  if (g.corrections.some(c => c.status === 'waiting_applicant')) return 'Documentos por subsanar'
  if (g.corrections.some(c => c.status === 'submitted')) return 'Documentos en revisión'
  return estado === 'approved' ? 'Aprobada, pendiente de registro de ingreso' : null
}

export function leerGestion(cuerpo: unknown): Gestion {
  const g = esObjeto(cuerpo) && esObjeto(cuerpo.data) ? cuerpo.data : null
  const texto = (v: unknown): v is string => typeof v === 'string' && v.length > 0
  const fecha = (v: unknown) => texto(v) && Number.isFinite(Date.parse(v))
  const acuerdo = (v: unknown) => esObjeto(v) && ['approved','rejected'].includes(String(v.outcome)) && texto(v.reason) && fecha(v.meeting_date) && ['ordinary','extraordinary'].includes(String(v.meeting_type))
  const apelacion = (v: unknown) => v === null || (esObjeto(v) && texto(v.id) && texto(v.reason) && fecha(v.requested_at)
    && (v.status === 'pending_assembly' && v.resolution === null || v.status === 'resolved' && acuerdo(v.resolution)))
  const correccion = (v: unknown) => esObjeto(v) && texto(v.id) && texto(v.reason) && fecha(v.requested_at)
    && ['waiting_applicant','submitted','accepted','needs_changes'].includes(String(v.status))
    && Array.isArray(v.requested_kinds) && v.requested_kinds.length > 0 && v.requested_kinds.every(k => typeof k === 'string' && k in NOMBRES_DOCUMENTOS)
    && Array.isArray(v.documents) && v.documents.every(d => esObjeto(d) && texto(d.id) && texto(d.original_name) && typeof d.version_no === 'number' && d.version_no >= 2 && typeof d.kind === 'string' && d.kind in NOMBRES_DOCUMENTOS)
    && (v.review === null || esObjeto(v.review) && ['accepted','needs_changes'].includes(String(v.review.outcome)) && texto(v.review.reason) && fecha(v.review.reviewed_at))
  if (!g || !Array.isArray(g.corrections) || !g.corrections.every(correccion) || !['approved', 'rejected', null].includes(g.effective_outcome as string | null)
    || typeof g.formalization_enabled !== 'boolean' || !apelacion(g.appeal)
    || !(g.membership === null || esObjeto(g.membership) && fecha(g.membership.effective_date) && fecha(g.membership.registered_at))) {
    throw new ApiError(502, 'No pudimos comprobar el estado del expediente. Vuelve a consultarlo.', {}, undefined, true)
  }
  return g as Gestion
}

export const AVANCES: Record<string,string> = {
  received: 'Recibida', under_review: 'En revisión', approved: 'Aprobada', rejected: 'Rechazada por el Directorio',
  waiting_applicant: 'Documentos por subsanar', documents_submitted: 'Subsanación por revisar', pending_assembly: 'Apelación pendiente de Asamblea',
  assembly_approved: 'Aprobada por la Asamblea', assembly_rejected: 'Rechazo confirmado por la Asamblea',
  approved_unregistered: 'Ingreso aprobado por registrar', membership_registered: 'Ingreso registrado',
}
