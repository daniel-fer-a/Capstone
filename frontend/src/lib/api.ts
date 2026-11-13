import type { DatosNatural, Comprobante } from '../postulacion/postulacionContext'

export type CampoFormulario =
  | 'nombres' | 'apellidos' | 'edad' | 'identificacion' | 'correo'
  | 'region' | 'comuna' | 'actividad' | 'respaldo' | 'confirmacion' | 'patrocinios'
  | 'razonSocial' | 'rutOrganizacion' | 'representante' | 'rutRepresentante' | 'estatutos' | 'vigencia'

export class ApiError extends Error {
  status: number
  campos: Partial<Record<CampoFormulario, string>>
  reintentarEnSegundos?: number
  incierto: boolean

  constructor(status: number, mensaje: string, campos: Partial<Record<CampoFormulario, string>> = {}, reintentarEnSegundos?: number, incierto = false) {
    super(mensaje)
    this.name = 'ApiError'
    this.status = status
    this.campos = campos
    this.reintentarEnSegundos = reintentarEnSegundos
    this.incierto = incierto
  }
}

const CAMPOS_API: Record<string, [CampoFormulario, string]> = {
  name: ['nombres', 'Revisa tus nombres y apellidos.'],
  givenNames: ['nombres', 'Revisa tus nombres.'],
  familyNames: ['apellidos', 'Revisa tus apellidos.'],
  age: ['edad', 'Ingresa una edad válida en años cumplidos.'],
  identifier: ['identificacion', 'Revisa tu identificación.'],
  email: ['correo', 'Ingresa un correo electrónico válido.'],
  address: ['region', 'Revisa tu región y comuna.'],
  region: ['region', 'Revisa tu región.'],
  commune: ['comuna', 'Revisa tu comuna.'],
  aiActivity: ['actividad', 'Revisa la descripción de tu actividad.'],
  declaration_accepted: ['confirmacion', 'Confirma que revisaste los datos de la postulación.'],
  sponsors: ['patrocinios', 'Se requieren dos patrocinios válidos de socios distintos.'],
  sponsorEmails: ['patrocinios', 'Indica dos correos válidos, distintos entre sí y diferentes del tuyo.'],
  sharing_accepted: ['confirmacion', 'Autoriza compartir los antecedentes indicados con los socios invitados.'],
  organizationName: ['razonSocial', 'Revisa la razón social.'],
  organizationId: ['rutOrganizacion', 'Revisa el RUT de la organización.'],
  representativeName: ['representante', 'Revisa el nombre completo del representante.'],
  representativeId: ['rutRepresentante', 'Revisa el RUT del representante.'],
  'documents.estatutos': ['estatutos', 'Adjunta los estatutos en PDF, JPG o PNG de hasta 5 MB.'],
  'documents.vigencia': ['vigencia', 'Adjunta la vigencia en PDF, JPG o PNG de hasta 5 MB.'],
  'documents.antecedentes': ['respaldo', 'El archivo debe ser PDF, JPG o PNG de hasta 5 MB.'],
}

export function esperaReintento(valor: string | null, ahora = Date.now()): number | undefined {
  if (!valor?.trim()) return undefined
  const segundos = /^\d+$/.test(valor) ? Number(valor) : Math.ceil((Date.parse(valor) - ahora) / 1000)
  return Number.isFinite(segundos) && segundos > 0 ? segundos : undefined
}

export function esObjeto(valor: unknown): valor is Record<string, unknown> {
  return typeof valor === 'object' && valor !== null && !Array.isArray(valor)
}

export async function solicitudJson(ruta: string, opciones: RequestInit = {}, estadoEsperado?: number): Promise<unknown> {
  const control = new AbortController()
  const temporizador = globalThis.setTimeout(() => control.abort(), 60_000)
  try {
    const respuesta = await fetch(ruta, {
      ...opciones,
      headers: { Accept: 'application/json', ...opciones.headers },
      signal: control.signal,
      cache: 'no-store',
      referrerPolicy: 'same-origin',
    })
    const cuerpo: unknown = await respuesta.json().catch(() => null)
    if (respuesta.ok && estadoEsperado !== undefined && respuesta.status !== estadoEsperado) {
      throw new ApiError(502, 'El servidor respondió, pero no pudimos confirmar el comprobante. Reintenta este mismo envío sin modificar los datos.', {}, undefined, true)
    }
    if (respuesta.ok && esObjeto(cuerpo)) return cuerpo
    if (respuesta.status === 422) {
      const campos: Partial<Record<CampoFormulario, string>> = {}
      const errores = esObjeto(cuerpo) && esObjeto(cuerpo.errors) ? cuerpo.errors : {}
      for (const clave of Object.keys(errores)) {
        const destino = CAMPOS_API[clave] ?? (clave.startsWith('sponsors.') ? CAMPOS_API.sponsors : clave.startsWith('sponsorEmails.') ? CAMPOS_API.sponsorEmails : undefined)
        if (destino) campos[destino[0]] = destino[1]
      }
      throw new ApiError(422, 'Revisa los datos e inténtalo nuevamente.', campos)
    }
    if (respuesta.status === 429) {
      throw new ApiError(429, 'Se realizaron demasiados intentos. Espera antes de volver a intentarlo.', {}, esperaReintento(respuesta.headers.get('Retry-After')))
    }
    const mensajes: Record<number, string> = {
      401: 'Inicia sesión para continuar.',
      403: 'Tu cuenta no tiene autorización para realizar esta acción.',
      404: 'No encontramos un registro con esos datos.',
      409: 'Este envío ya fue registrado o cambió de estado. Verifica su recepción antes de iniciar otra postulación.',
      413: 'El envío supera el tamaño permitido. Revisa el archivo adjunto.',
      419: 'La sesión de seguridad expiró. Inténtalo nuevamente.',
    }
    throw new ApiError(respuesta.status, mensajes[respuesta.status] ?? 'No pudimos confirmar la operación. Inténtalo de nuevo en unos minutos.', {}, undefined, respuesta.ok || respuesta.status >= 500)
  } catch (error) {
    if (error instanceof ApiError) throw error
    throw new ApiError(0, 'No pudimos confirmar la respuesta del servidor. Puedes reintentar este mismo envío sin cambiar sus datos.', {}, undefined, true)
  } finally {
    globalThis.clearTimeout(temporizador)
  }
}

export type CapacidadIngreso = {
  natural_submission_enabled: boolean
  local_pilot: boolean
  sponsor_channel_available?: boolean
  juridical_submission_enabled?: boolean
}

export async function capacidadIngreso(): Promise<CapacidadIngreso> {
  const cuerpo = await solicitudJson('/api/v1/admissions/capabilities', { credentials: 'omit' })
  if (!esObjeto(cuerpo) || !esObjeto(cuerpo.data) || typeof cuerpo.data.natural_submission_enabled !== 'boolean' || typeof cuerpo.data.local_pilot !== 'boolean') {
    throw new ApiError(502, 'No pudimos comprobar la disponibilidad de las postulaciones.')
  }
  return cuerpo.data as CapacidadIngreso
}

export async function enviarPostulacionNatural(datos: DatosNatural, respaldo: File | null, clave: string): Promise<Comprobante> {
  const form = new FormData()
  form.set('membership', 'member')
  form.set('legal', 'natural')
  form.set('givenNames', datos.nombres.trim())
  form.set('familyNames', datos.apellidos.trim())
  form.set('age', String(Number(datos.edad)))
  form.set('identifier', datos.identificacion.trim())
  form.set('email', datos.correo.trim())
  form.set('region', datos.region.trim())
  form.set('commune', datos.comuna.trim())
  form.set('aiActivity', datos.actividad.trim())
  form.set('declaration_accepted', '1')
  if (respaldo) form.set('documents[antecedentes]', respaldo)
  const cuerpo = await solicitudJson('/api/v1/applications', {
    method: 'POST', credentials: 'omit', headers: { 'Idempotency-Key': clave }, body: form,
  }, 201)
  return leerComprobante(cuerpo)
}

export function leerComprobante(cuerpo: unknown): Comprobante {
  const d = esObjeto(cuerpo) && esObjeto(cuerpo.data) ? cuerpo.data : null
  if (!d || typeof d.id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(d.id)
    || typeof d.reference !== 'string' || !/^CCHIA-[A-Z0-9-]{1,34}$/.test(d.reference)
    || typeof d.status !== 'string' || !['received', 'under_review', 'approved', 'rejected'].includes(d.status)
    || typeof d.local_pilot !== 'boolean'
    || typeof d.submitted_at !== 'string' || !Number.isFinite(Date.parse(d.submitted_at))
    || typeof d.tracking_token !== 'string' || !/^[A-Za-z0-9]{64}$/.test(d.tracking_token)) {
    throw new ApiError(502, 'El servidor respondió, pero no pudimos leer el comprobante. Reintenta este mismo envío sin modificar los datos.', {}, undefined, true)
  }
  return {
    id: d.id, folio: d.reference, estado: d.status, recibidaEn: d.submitted_at,
    codigoSeguimiento: d.tracking_token, prueba: d.local_pilot === true,
  }
}
