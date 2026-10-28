import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, botonPrimario, botonSecundario, Pagina } from '../components/ui'
import { fechaLarga } from '../lib/formato'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function Comprobante() {
  const { comprobante } = usePostulacion()
  const [verCodigo, setVerCodigo] = useState(false)
  const [copiado, setCopiado] = useState(false)

  // El código solo se entrega una vez: avisamos antes de cerrar o recargar.
  useEffect(() => {
    if (!comprobante) return
    const aviso = (ev: BeforeUnloadEvent) => ev.preventDefault()
    window.addEventListener('beforeunload', aviso)
    return () => window.removeEventListener('beforeunload', aviso)
  }, [comprobante])

  if (!comprobante) {
    return (
      <Pagina
        migas="Postulación / Comprobante"
        etiqueta="Comprobante"
        titulo="No hay un comprobante para mostrar"
        bajada="Por seguridad, el código de seguimiento se muestra una sola vez, al enviar la postulación."
      >
        <Link to="/postular" className={botonSecundario}>Ir a postular</Link>
      </Pagina>
    )
  }

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(comprobante.codigoSeguimiento)
      setCopiado(true)
    } catch {
      setCopiado(false)
    }
  }

  const descargar = () => {
    const texto = [
      'CCHIA · Comprobante de recepción de postulación',
      '',
      `Folio: ${comprobante.folio}`,
      `Fecha de recepción: ${fechaLarga(comprobante.recibidaEn)}`,
      `Código de seguimiento: ${comprobante.codigoSeguimiento}`,
      '',
      'Guarda este archivo en un lugar seguro y no compartas el código.',
      'La recepción no otorga membresía ni crea una cuenta de acceso.',
    ].join('\r\n')
    const url = URL.createObjectURL(new Blob([texto], { type: 'text/plain;charset=utf-8' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `comprobante-${comprobante.folio}.txt`
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <Pagina
      migas="Postulación / Comprobante"
      etiqueta="Solicitud registrada"
      titulo="Recibimos tu postulación"
      bajada="Conserva el comprobante y el código privado para consultar el avance de tu solicitud."
    >
      <div className="flex flex-col gap-6 md:flex-row">
        <Pasos actual={4} />
        <section className="flex-1 rounded-xl border border-line bg-white p-6">
          <h2 className="text-2xl font-bold text-navy">Postulación recibida</h2>
          <span className="mt-2 inline-block rounded-full bg-tint px-3 py-1 text-xs font-bold text-navy">Recibida</span>

          <dl className="mt-5 grid gap-4 border-y border-line py-4 sm:grid-cols-2">
            <div>
              <dt className="text-xs text-muted">Folio de postulación</dt>
              <dd className="font-mono text-sm font-bold break-all">{comprobante.folio}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted">Fecha de recepción</dt>
              <dd className="text-sm">{fechaLarga(comprobante.recibidaEn)}</dd>
            </div>
          </dl>

          <p className="mt-4 text-sm">
            El código privado se entrega una sola vez. Guárdalo junto con el folio antes de cerrar esta pantalla.
          </p>

          <div className="mt-3 rounded-md border border-line p-3">
            {verCodigo ? (
              <div className="flex flex-wrap items-center gap-3">
                <code className="font-mono text-sm font-bold break-all">{comprobante.codigoSeguimiento}</code>
                <button type="button" onClick={copiar} className="text-sm text-navy underline">
                  {copiado ? 'Copiado ✓' : 'Copiar'}
                </button>
              </div>
            ) : (
              <button type="button" onClick={() => setVerCodigo(true)} className="text-sm font-bold text-navy">
                ▸ Ver código de seguimiento
              </button>
            )}
          </div>
          <p className="mt-1.5 text-xs text-muted">No compartas tu código en grupos ni en capturas de pantalla.</p>

          <div className="mt-5 flex flex-wrap gap-3">
            <button type="button" onClick={descargar} className={botonPrimario}>Descargar comprobante</button>
            <Link to="/seguimiento" className={botonSecundario}>Consultar estado</Link>
          </div>

          <div className="mt-5">
            <Aviso>
              La recepción no crea una cuenta de acceso ni otorga membresía automática. El resultado se
              comunicará dentro del proceso de revisión institucional.
            </Aviso>
          </div>
        </section>
      </div>
    </Pagina>
  )
}
