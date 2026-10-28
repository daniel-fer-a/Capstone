import type { DatosNatural, Comprobante } from '../postulacion/postulacionContext'

export type CampoFormulario =
  | 'nombres'
  | 'apellidos'
  | 'identificacion'
  | 'correo'
  | 'domicilio'
  | 'actividad'
  | 'respaldo'
  | 'confirmacion'

export class ApiError extends Error {
  status: number
  campos: Partial<Record<CampoFormulario, string>>
  reintentarEnSegundos?: number

  constructor(
    status: number,
    mensaje: string,
    campos: Partial<Record<CampoFormulario, string>> = {},
    reintentarEnSegundos?: number,
  ) {
    super(mensaje)
    this.name = 'ApiError'
    this.status = status
    this.campos = campos
    this.reintentarEnSegundos = reintentarEnSegundos
  }
}

// Campo de la API -> campo del formulario y mensaje propio en español.
// No se muestran los mensajes del servidor tal cual: algunos vienen en inglés.
const CAMPOS_API: Record<string, [CampoFormulario, string]> = {
  name: ['nombres', 'Revisa tus nombres y apellidos.'],
  identifier: ['identificacion', 'Revisa tu identificación.'],
  email: ['correo', 'Ingresa un correo electrónico válido.'],
  address: ['domicilio', 'Revisa este dato.'],
  aiActivity: ['actividad', 'Revisa la descripción de tu actividad.'],
  declaration_accepted: ['confirmacion', 'Confirma que revisaste los datos de la postulación.'],
  'documents.antecedentes': ['respaldo', 'El archivo debe ser PDF, JPG o PNG de hasta 5 MB.'],
}

const TIEMPO_MAXIMO_MS = 60_000

export async function enviarPostulacionNatural(
  datos: DatosNatural,
  respaldo: File | null,
): Promise<Comprobante> {
  const form = new FormData()
  form.set('membership', 'member')
  form.set('legal', 'natural')
  form.set('name', `${datos.nombres.trim()} ${datos.apellidos.trim()}`.trim())
  form.set('identifier', datos.identificacion.trim())
  form.set('email', datos.correo.trim())
  form.set('address', datos.domicilio.trim())
  form.set('aiActivity', datos.actividad.trim())
  form.set('declaration_accepted', '1')
  if (respaldo) form.set('documents[antecedentes]', respaldo)

  const control = new AbortController()
  const temporizador = window.setTimeout(() => control.abort(), TIEMPO_MAXIMO_MS)

  let respuesta: Response
  try {
    respuesta = await fetch('/api/v1/applications', {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: form,
      signal: control.signal,
    })
  } catch {
    throw new ApiError(0, 'No pudimos conectarnos con el servidor. Revisa tu conexión e inténtalo de nuevo.')
  } finally {
    window.clearTimeout(temporizador)
  }

  if (respuesta.status === 201) {
    const cuerpo = (await respuesta.json()) as {
      data: { id: string; reference: string; status: string; submitted_at: string; tracking_token: string }
    }
    const d = cuerpo.data
    return {
      id: d.id,
      folio: d.reference,
      estado: d.status,
      recibidaEn: d.submitted_at,
      codigoSeguimiento: d.tracking_token,
    }
  }

  if (respuesta.status === 422) {
    const cuerpo = (await respuesta.json().catch(() => ({}))) as { errors?: Record<string, string[]> }
    const campos: Partial<Record<CampoFormulario, string>> = {}
    for (const clave of Object.keys(cuerpo.errors ?? {})) {
      const destino = CAMPOS_API[clave]
      if (destino) campos[destino[0]] = destino[1]
    }
    throw new ApiError(422, 'Hay datos que debes corregir antes de enviar.', campos)
  }

  if (respuesta.status === 429) {
    const espera = Number(respuesta.headers.get('Retry-After'))
    throw new ApiError(
      429,
      'Se realizaron demasiados intentos seguidos. Espera un momento e inténtalo de nuevo.',
      {},
      Number.isFinite(espera) && espera > 0 ? espera : undefined,
    )
  }

  throw new ApiError(respuesta.status, 'No pudimos registrar tu postulación. Inténtalo nuevamente en unos minutos.')
}
