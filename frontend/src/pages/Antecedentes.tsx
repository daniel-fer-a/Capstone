import { useState, type ChangeEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario } from '../components/estilos'
import type { CampoFormulario } from '../lib/api'
import { MAX_ARCHIVO_BYTES, TIPOS_ARCHIVO, tamano } from '../lib/formato'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function Antecedentes() {
  const navigate = useNavigate()
  const location = useLocation()
  const { datos, respaldo, setRespaldo } = usePostulacion()
  const errorServidor = (location.state as { errores?: Partial<Record<CampoFormulario, string>> } | null)?.errores?.respaldo
  const [error, setError] = useState<string | undefined>(errorServidor)

  if (!datos) return <Navigate to="/postular" replace />

  const elegir = (ev: ChangeEvent<HTMLInputElement>) => {
    const archivo = ev.target.files?.[0] ?? null
    if (!archivo) return
    if (!TIPOS_ARCHIVO.includes(archivo.type) || !/\.(pdf|jpe?g|png)$/i.test(archivo.name)) {
      setRespaldo(null)
      setError('El archivo debe ser PDF, JPG o PNG.')
      ev.target.value = ''
      return
    }
    if (archivo.size === 0 || archivo.size > MAX_ARCHIVO_BYTES) {
      setRespaldo(null)
      setError('El archivo debe tener contenido y no superar los 5 MB.')
      ev.target.value = ''
      return
    }
    setError(undefined)
    setRespaldo(archivo)
  }

  return (
    <Pagina
      migas="Postulación / Antecedentes"
      etiqueta="Postulación de socio"
      titulo="Antecedentes"
      bajada="Si tienes un respaldo de tu actividad, puedes adjuntarlo. Es opcional."
    >
      <div className="flex flex-col gap-6 md:flex-row">
        <Pasos actual={2} />
        <section className="min-w-0 flex-1 rounded-xl border border-line bg-white p-6">
          <h2 className="text-xl font-bold text-navy">Respaldo de tu actividad (opcional)</h2>
          <p className="mb-5 text-sm text-muted">PDF, JPG o PNG. Máximo 5 MB.</p>

          <label htmlFor="respaldo" className="mb-1.5 block text-sm font-bold">Archivo de respaldo</label>
          <input
            id="respaldo"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
            onChange={elegir}
            aria-invalid={!!error}
            aria-describedby={error ? 'respaldo-error' : undefined}
            className="block w-full rounded-md border border-line bg-white p-2.5 text-sm"
          />
          {error && (
            <p id="respaldo-error" role="alert" className="mt-1.5 text-xs font-semibold text-danger">{error}</p>
          )}
          {error && !respaldo && <button type="button" className="mt-2 text-sm text-navy underline" onClick={() => { setError(undefined); setRespaldo(null) }}>Continuar sin archivo</button>}
          {respaldo && (
            <p className="mt-2 text-sm text-ok">
              ✓ {respaldo.name} · {tamano(respaldo.size)}{' '}
              <button type="button" onClick={() => { setError(undefined); setRespaldo(null); const input = document.getElementById('respaldo') as HTMLInputElement | null; if (input) input.value = '' }} className="ml-2 text-navy underline">
                Quitar
              </button>
            </p>
          )}

          <div className="mt-6">
            <Aviso>
              Tus datos y archivos son privados. Solo las personas autorizadas para la revisión institucional pueden consultarlos.
            </Aviso>
          </div>

          <div className="mt-6 flex justify-end gap-3">
            <button type="button" className={botonSecundario} onClick={() => navigate('/postular/natural/datos')}>
              Volver
            </button>
            <button
              type="button"
              className={botonPrimario}
              disabled={!!error}
              onClick={() => navigate('/postular/natural/revision')}
            >
              Revisar postulación →
            </button>
          </div>
        </section>
      </div>
    </Pagina>
  )
}
