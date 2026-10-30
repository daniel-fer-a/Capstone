import { useState, type FormEvent } from 'react'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, inputCls } from '../components/estilos'
import { ApiError, esObjeto, solicitudJson } from '../lib/api'
import { fechaLarga } from '../lib/formato'
import { nombreEstado } from '../lib/estados'
import { usePostulacion } from '../postulacion/postulacionContext'

type Seguimiento = {
  reference: string; status: string; submitted_at: string; decision_at: string | null
  history: { action: string; label: string; occurred_at: string }[]
}

export default function Seguimiento() {
  const { comprobante } = usePostulacion()
  const [folio, setFolio] = useState(comprobante?.folio ?? '')
  const [codigo, setCodigo] = useState('')
  const [mostrar, setMostrar] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')
  const [resultado, setResultado] = useState<Seguimiento | null>(null)

  const consultar = async (ev: FormEvent) => {
    ev.preventDefault()
    if (enviando) return
    setResultado(null)
    if (!folio.trim() || !/^[A-Za-z0-9]{64}$/.test(codigo.trim())) {
      setError('Ingresa el folio y el código privado de 64 caracteres que figuran en tu comprobante.')
      return
    }
    setEnviando(true)
    setError('')
    try {
      const cuerpo = await solicitudJson('/api/v1/applications/track', {
        method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reference: folio.trim().toUpperCase(), tracking_token: codigo.trim() }),
      })
      const d = esObjeto(cuerpo) && esObjeto(cuerpo.data) ? cuerpo.data : null
      if (!d || typeof d.reference !== 'string' || typeof d.status !== 'string' || typeof d.submitted_at !== 'string' || !Array.isArray(d.history)) throw new Error('Respuesta inválida')
      setResultado(d as Seguimiento)
    } catch (err) {
      setError(err instanceof ApiError && err.status === 404 ? 'No encontramos una solicitud con ese folio y código. Revisa ambos datos.' : err instanceof ApiError ? err.message : 'No pudimos consultar la solicitud. Inténtalo nuevamente.')
    } finally { setEnviando(false) }
  }

  return <Pagina migas="Consultar postulación" etiqueta="Consulta privada" titulo="Consulta tu postulación" bajada="Usa el folio y el código privado de tu comprobante. No necesitas iniciar sesión.">
    <form onSubmit={consultar} className="max-w-2xl space-y-5 rounded-xl border border-line bg-white p-5 sm:p-6" noValidate>
      <Field id="folio" label="Folio de postulación"><input id="folio" value={folio} onChange={(ev) => { setFolio(ev.target.value); setResultado(null) }} maxLength={40} autoComplete="off" className={inputCls(false)} required /></Field>
      <Field id="codigo" label="Código privado de seguimiento"><input id="codigo" type={mostrar ? 'text' : 'password'} value={codigo} onChange={(ev) => { setCodigo(ev.target.value); setResultado(null) }} maxLength={64} autoComplete="off" className={inputCls(false)} required /></Field>
      <button type="button" onClick={() => setMostrar((v) => !v)} className="text-sm text-navy underline">{mostrar ? 'Ocultar código' : 'Mostrar código'}</button>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <div><button type="submit" disabled={enviando} className={botonPrimario}>{enviando ? 'Consultando…' : 'Consultar estado'}</button></div>
    </form>
    {resultado && <section className="mt-6 max-w-2xl rounded-xl border border-line bg-white p-5 sm:p-6" aria-live="polite">
      <h2 className="text-2xl font-bold text-navy">{nombreEstado(resultado.status)}</h2>
      <p className="mt-2 break-all font-mono text-sm">{resultado.reference}</p>
      <p className="mt-2 text-sm text-muted">Recibida el {fechaLarga(resultado.submitted_at)}</p>
      <h3 className="mt-6 font-bold text-navy">Historial de tu solicitud</h3>
      <ol className="mt-3 space-y-3">{resultado.history.map((item, i) => <li key={`${item.action}-${i}`} className="border-l-2 border-teal pl-4 text-sm"><p className="font-semibold">{item.label}</p><p className="text-xs text-muted">{fechaLarga(item.occurred_at)}</p></li>)}</ol>
      <div className="mt-5"><Aviso>La recepción y el inicio de la revisión no otorgan membresía automática. Conserva tu comprobante mientras se resuelve la solicitud.</Aviso></div>
    </section>}
  </Pagina>
}
