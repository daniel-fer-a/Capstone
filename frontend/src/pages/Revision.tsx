import { useEffect, useRef, useState } from 'react'
import { Navigate, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario } from '../components/estilos'
import { ApiError, capacidadIngreso, enviarPostulacionNatural, type CapacidadIngreso } from '../lib/api'
import { tamano } from '../lib/formato'
import { usePostulacion } from '../postulacion/postulacionContext'

const CAMPOS_DATOS = ['nombres', 'apellidos', 'edad', 'identificacion', 'correo', 'region', 'comuna', 'actividad']

export default function Revision() {
  const navigate = useNavigate()
  const { datos, respaldo, comprobante, setDatos, setRespaldo, setComprobante, claveEnvio } = usePostulacion()
  const [confirmado, setConfirmado] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const [falla, setFalla] = useState<string | null>(null)
  const [incierto, setIncierto] = useState(false)
  const [conflicto, setConflicto] = useState(false)
  const [capacidad, setCapacidad] = useState<CapacidadIngreso | null>(null)
  const [errorCapacidad, setErrorCapacidad] = useState(false)
  const [consulta, setConsulta] = useState(0)
  const [espera, setEspera] = useState(0)
  const bloqueo = useRef(false)

  useEffect(() => {
    let activo = true
    capacidadIngreso().then((valor) => { if (activo) { setCapacidad(valor); setErrorCapacidad(false) } })
      .catch(() => { if (activo) setErrorCapacidad(true) })
    return () => { activo = false }
  }, [consulta])

  useEffect(() => {
    if (espera <= 0) return
    const temporizador = window.setTimeout(() => setEspera((s) => Math.max(0, s - 1)), 1000)
    return () => window.clearTimeout(temporizador)
  }, [espera])

  useEffect(() => {
    if (!enviando && !incierto) return
    const aviso = (ev: BeforeUnloadEvent) => ev.preventDefault()
    window.addEventListener('beforeunload', aviso)
    return () => window.removeEventListener('beforeunload', aviso)
  }, [enviando, incierto])

  if (!datos) return <Navigate to={comprobante ? '/postular/comprobante' : '/postular'} replace />

  const filas: [string, string][] = [
    ['Modalidad', 'Socio · Persona natural'], ['Nombres', datos.nombres], ['Apellidos', datos.apellidos],
    ['Edad', `${datos.edad} años`], ['Identificación', datos.identificacion], ['Correo electrónico', datos.correo],
    ['Región', datos.region], ['Comuna', datos.comuna], ['Actividad de IA', datos.actividad],
    ['Respaldo', respaldo ? `${respaldo.name} · ${tamano(respaldo.size)}` : 'Sin respaldo adicional'],
  ]

  const enviar = async () => {
    if (bloqueo.current || conflicto || !confirmado || espera > 0 || !capacidad?.natural_submission_enabled) return
    bloqueo.current = true
    setEnviando(true)
    setFalla(null)
    try {
      const recibido = await enviarPostulacionNatural(datos, respaldo, claveEnvio)
      setComprobante(recibido)
      setDatos(null)
      setRespaldo(null)
      setIncierto(false)
      navigate('/postular/comprobante', { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setIncierto(false)
        if (CAMPOS_DATOS.some((campo) => campo in err.campos)) {
          navigate('/postular/natural/datos', { state: { errores: err.campos } })
          return
        }
        if (err.campos.respaldo) {
          navigate('/postular/natural/antecedentes', { state: { errores: err.campos } })
          return
        }
        setFalla(err.campos.patrocinios ?? err.campos.confirmacion ?? err.message)
      } else {
        setFalla(err instanceof ApiError ? err.message : 'No pudimos confirmar el envío. Reintenta sin cambiar los datos.')
        setIncierto(!(err instanceof ApiError) || err.incierto || err.status === 409)
        if (err instanceof ApiError && err.status === 409) setConflicto(true)
        if (err instanceof ApiError && err.status === 429) setEspera(err.reintentarEnSegundos ?? 60)
      }
    } finally {
      bloqueo.current = false
      setEnviando(false)
    }
  }

  return (
    <Pagina migas="Postulación / Revisión" etiqueta="Antes de enviar" titulo="Revisa tu postulación"
      bajada="Comprueba los datos y antecedentes. Puedes volver a corregirlos antes de confirmar el envío.">
      <div className="flex min-w-0 flex-col gap-6 md:flex-row">
        <Pasos actual={3} piloto />
        <section className="min-w-0 flex-1 rounded-xl border border-line bg-white p-5 sm:p-6" aria-busy={enviando}>
          <div className="flex flex-wrap items-baseline justify-between gap-3">
            <h2 className="text-xl font-bold text-navy">Resumen de tu solicitud</h2>
            <button type="button" disabled={enviando || incierto} onClick={() => navigate('/postular/natural/datos')}
              className="text-sm text-navy underline disabled:opacity-50">Editar datos</button>
          </div>
          <dl className="mt-4 divide-y divide-line border-y border-line text-sm">
            {filas.map(([k, v]) => <div key={k} className="grid gap-1 py-3 sm:grid-cols-[180px_1fr]">
              <dt className="text-xs text-muted">{k}</dt><dd className="min-w-0 whitespace-pre-wrap">{v}</dd>
            </div>)}
          </dl>
          <div className="mt-5">
            <Aviso>El Directorio evaluará la solicitud conforme al procedimiento institucional. La recepción no equivale a la admisión como socio.</Aviso>
          </div>
          {capacidad?.local_pilot && <div className="mt-4"><Aviso tipo="warn">Entorno de pruebas: utiliza únicamente datos ficticios. Este envío no acredita los dos patrocinios y no constituye una postulación institucional completa.</Aviso></div>}
          {capacidad && !capacidad.natural_submission_enabled && <div className="mt-4"><Aviso tipo="warn">Para completar la postulación se requieren dos socios patrocinadores. El envío se habilitará cuando esté disponible el canal para acreditar estos patrocinios.</Aviso></div>}
          {!capacidad && !errorCapacidad && <p className="mt-4 text-sm" role="status">Comprobando disponibilidad…</p>}
          {errorCapacidad && <div className="mt-4"><Aviso tipo="error">No pudimos comprobar si el envío está disponible. <button className="underline" type="button" onClick={() => setConsulta((n) => n + 1)}>Volver a comprobar</button></Aviso></div>}
          <label className="mt-5 flex items-start gap-3 text-sm">
            <input type="checkbox" checked={confirmado} disabled={enviando || incierto} onChange={(ev) => setConfirmado(ev.target.checked)} className="mt-1 h-4 w-4 shrink-0" />
            Confirmo que revisé mis antecedentes y que corresponden a esta postulación.
          </label>
          {falla && <div className="mt-4"><Aviso tipo="error">{falla}</Aviso></div>}
          {incierto && <p className="mt-3 text-sm text-muted">{conflicto ? 'Conserva tu comprobante si lo tienes y consulta su estado. Si no lo conservas, contacta a la CCHIA para verificar la recepción antes de volver a postular.' : 'Conservamos la referencia de este envío para recuperar su comprobante al reintentar. Evita recargar la página o comenzar otra solicitud mientras no confirmes el resultado.'}</p>}
          {espera > 0 && <p role="status" className="mt-3 text-sm text-muted">Podrás reintentar en {espera} segundos.</p>}
          <div className="mt-6 flex flex-wrap justify-end gap-3">
            <button type="button" className={botonSecundario} disabled={enviando || incierto} onClick={() => navigate('/postular/natural/antecedentes')}>Volver</button>
            <button type="button" className={botonPrimario} disabled={!confirmado || enviando || conflicto || espera > 0 || !capacidad?.natural_submission_enabled || errorCapacidad} onClick={enviar}>
              {enviando ? 'Enviando…' : conflicto ? 'Requiere verificar recepción' : incierto ? 'Recuperar comprobante' : 'Enviar postulación →'}
            </button>
          </div>
        </section>
      </div>
    </Pagina>
  )
}
