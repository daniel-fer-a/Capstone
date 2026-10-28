import { useRef, useState } from 'react'
import { Navigate, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, botonPrimario, botonSecundario, Pagina } from '../components/ui'
import { ApiError, enviarPostulacionNatural } from '../lib/api'
import { tamano } from '../lib/formato'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function Revision() {
  const navigate = useNavigate()
  const { datos, setDatos, respaldo, setRespaldo, setComprobante } = usePostulacion()
  const [confirmado, setConfirmado] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const [falla, setFalla] = useState<string | null>(null)
  const enviada = useRef(false)

  // Tras enviar se vacía el borrador; sin esta marca la guarda de abajo
  // redirigiría a /postular antes de llegar al comprobante.
  if (!datos) return enviada.current ? null : <Navigate to="/postular" replace />

  const filas: [string, string][] = [
    ['Modalidad', 'Socio · Persona natural'],
    ['Nombres', datos.nombres],
    ['Apellidos', datos.apellidos],
    ['Identificación', datos.identificacion],
    ['Correo electrónico', datos.correo],
    ['Región y comuna', datos.domicilio],
    ['Actividad de IA', datos.actividad],
    ['Respaldo', respaldo ? `${respaldo.name} · ${tamano(respaldo.size)}` : 'Sin respaldo adicional'],
  ]

  const enviar = async () => {
    if (enviando) return
    setEnviando(true)
    setFalla(null)
    try {
      const comprobante = await enviarPostulacionNatural(datos, respaldo)
      enviada.current = true
      setComprobante(comprobante)
      setDatos(null)
      setRespaldo(null)
      navigate('/postular/comprobante', { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && Object.keys(err.campos).length > 0) {
        const destino = err.campos.respaldo && !err.campos.nombres ? 'antecedentes' : 'datos'
        navigate(`/postular/natural/${destino}`, { state: { errores: err.campos } })
        return
      }
      setFalla(err instanceof ApiError ? err.message : 'Ocurrió un error inesperado. Inténtalo nuevamente.')
      setEnviando(false)
    }
  }

  return (
    <Pagina
      migas="Postulación / Revisión"
      etiqueta="Antes de enviar"
      titulo="Revisa tu postulación"
      bajada="Comprueba los datos y antecedentes. Puedes volver a corregirlos antes de confirmar el envío."
    >
      <div className="flex flex-col gap-6 md:flex-row">
        <Pasos actual={3} />
        <section className="flex-1 rounded-xl border border-line bg-white p-6">
          <div className="flex items-baseline justify-between">
            <h2 className="text-xl font-bold text-navy">Resumen de tu solicitud</h2>
            <button
              type="button"
              onClick={() => navigate('/postular/natural/datos')}
              className="text-sm text-navy underline"
            >
              Editar datos
            </button>
          </div>
          <dl className="mt-4 divide-y divide-line border-y border-line text-sm">
            {filas.map(([k, v]) => (
              <div key={k} className="grid gap-1 py-3 sm:grid-cols-[180px_1fr]">
                <dt className="text-xs text-muted">{k}</dt>
                <dd className="break-words whitespace-pre-wrap">{v}</dd>
              </div>
            ))}
          </dl>

          <div className="mt-5">
            <Aviso>
              El Directorio evaluará la solicitud conforme al procedimiento institucional. La
              recepción no equivale a la admisión como socio.
            </Aviso>
          </div>

          <label className="mt-5 flex items-start gap-3 text-sm">
            <input
              type="checkbox"
              checked={confirmado}
              onChange={(ev) => setConfirmado(ev.target.checked)}
              className="mt-1 h-4 w-4"
            />
            Confirmo que revisé mis antecedentes y que corresponden a esta postulación.
          </label>

          {falla && (
            <div className="mt-4">
              <Aviso tipo="error">{falla}</Aviso>
            </div>
          )}

          <div className="mt-6 flex justify-end gap-3">
            <button
              type="button"
              className={botonSecundario}
              disabled={enviando}
              onClick={() => navigate('/postular/natural/antecedentes')}
            >
              Volver
            </button>
            <button type="button" className={botonPrimario} disabled={!confirmado || enviando} onClick={enviar}>
              {enviando ? 'Enviando…' : 'Enviar postulación →'}
            </button>
          </div>
        </section>
      </div>
    </Pagina>
  )
}
