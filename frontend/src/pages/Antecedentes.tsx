import { useState, type ChangeEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, botonPrimario, botonSecundario, Pagina } from '../components/ui'
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
    if (!TIPOS_ARCHIVO.includes(archivo.type)) {
      setError('El archivo debe ser PDF, JPG o PNG.')
      ev.target.value = ''
      return
    }
    if (archivo.size > MAX_ARCHIVO_BYTES) {
      setError('El archivo supera los 5 MB permitidos.')
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
        <section className="flex-1 rounded-xl border border-line bg-white p-6">
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
          {respaldo && !error && (
            <p className="mt-2 text-sm text-ok">
              ✓ {respaldo.name} · {tamano(respaldo.size)}{' '}
              <button type="button" onClick={() => setRespaldo(null)} className="ml-2 text-navy underline">
                Quitar
              </button>
            </p>
          )}

          <div className="mt-6">
            <Aviso>
              Tus datos y archivos se tratan como antecedentes privados y solo los revisa el Directorio.
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
