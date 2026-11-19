import { useRef, useState, type FormEvent } from 'react'
import { ApiError, solicitudJson } from '../lib/api'
import { leerGestion, NOMBRES_DOCUMENTOS, type CredencialesExpediente, type Gestion, type Subsanacion } from '../lib/expediente'
import { MAX_ARCHIVO_BYTES, TIPOS_ARCHIVO, fechaLarga } from '../lib/formato'
import { Aviso, Field } from './ui'
import { botonPrimario, inputCls } from './estilos'

export default function ContinuidadPostulante({ gestion, estado, credenciales, actualizar }: { gestion: Gestion; estado: string; credenciales: CredencialesExpediente; actualizar: () => Promise<void> }) {
  return <div className="mt-6 space-y-6 border-t border-line pt-5">
    {gestion.appeal && <section className="space-y-3 text-sm"><h3 className="text-lg font-bold text-navy">Apelación</h3><p>Solicitada ante el Directorio el {fechaLarga(gestion.appeal.requested_at)}.</p><p className="whitespace-pre-wrap break-words">{gestion.appeal.reason}</p>
      {gestion.appeal.resolution ? <><h4 className="font-bold">Acuerdo de la Asamblea</h4><p>Sesión {gestion.appeal.resolution.meeting_type === 'ordinary' ? 'ordinaria' : 'extraordinaria'} del {gestion.appeal.resolution.meeting_date}.</p><p className="whitespace-pre-wrap break-words">{gestion.appeal.resolution.reason}</p>{gestion.appeal.resolution.outcome === 'rejected' && <Aviso>Se conservan el rechazo inicial y el acuerdo de la Asamblea en el mismo expediente. Para otras gestiones, contacta a la CCHIA.</Aviso>}</>
        : <Aviso>Tu solicitud está dirigida al Directorio para que se pronuncie la próxima Asamblea de socios. Conserva tu comprobante.</Aviso>}
    </section>}
    {estado === 'rejected' && !gestion.appeal && <SolicitarApelacion credenciales={credenciales} actualizar={actualizar} />}
    {gestion.corrections.map(c => <section key={c.id} className="space-y-3 text-sm"><h3 className="text-lg font-bold text-navy">Subsanación de documentos</h3><p>{fechaLarga(c.requested_at)}</p><p className="whitespace-pre-wrap break-words">{c.reason}</p>
      <p>Documentos solicitados: {c.requested_kinds.map(k => NOMBRES_DOCUMENTOS[k] ?? k).join(', ')}.</p>
      {c.status === 'waiting_applicant' ? <EnviarSubsanacion solicitud={c} credenciales={credenciales} actualizar={actualizar} /> : <>
        <p className="font-semibold">{c.status === 'submitted' ? 'Nuevas versiones recibidas; pendientes de revisión.' : c.status === 'accepted' ? 'Documentos aceptados en esta revisión.' : 'Documentos observados por la revisión.'}</p>
        <ul className="space-y-1">{c.documents.map(d => <li key={d.id} className="break-all">{NOMBRES_DOCUMENTOS[d.kind]} · Versión {d.version_no} · {d.original_name}</li>)}</ul>
        {c.review && <p className="whitespace-pre-wrap break-words">{c.review.reason}</p>}
      </>}
    </section>)}
    {gestion.membership && <section className="space-y-2 text-sm"><h3 className="text-lg font-bold text-navy">Ingreso registrado</h3><p>Fecha efectiva: {gestion.membership.effective_date}.</p><p>El ingreso quedó registrado expresamente por la CCHIA. La habilitación de tu acceso se gestiona por separado.</p></section>}
  </div>
}

function SolicitarApelacion({ credenciales, actualizar }: { credenciales: CredencialesExpediente; actualizar: () => Promise<void> }) {
  const [motivo, setMotivo] = useState(''), [confirmado, setConfirmado] = useState(false), [ocupado, setOcupado] = useState(false), [incierto, setIncierto] = useState(false), [error, setError] = useState('')
  const bloqueo = useRef(false)
  const enviar = async (ev: FormEvent) => {
    ev.preventDefault()
    if (bloqueo.current || !confirmado || !motivo.trim()) return
    bloqueo.current = true; setOcupado(true); setError('')
    try {
      leerGestion(await solicitudJson('/api/v1/applications/appeal', { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...credenciales, reason: motivo.trim(), confirmation: true }) }))
      await actualizar()
    } catch (e) { setIncierto(e instanceof ApiError && e.incierto); setError(e instanceof ApiError ? e.message : 'Consulta el expediente antes de volver a enviar.') }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  return <form onSubmit={enviar} className="space-y-4"><h3 className="text-lg font-bold text-navy">Solicitar apelación ante el Directorio</h3><p className="text-sm">La próxima Asamblea resuelve la apelación. Esta gestión conserva el rechazo inicial y tu mismo folio.</p>
    <fieldset disabled={ocupado || incierto} className="space-y-4"><Field id="motivo-apelacion" label="Fundamento de la apelación"><textarea id="motivo-apelacion" value={motivo} onChange={e => setMotivo(e.target.value)} rows={4} maxLength={10000} required className={inputCls(false)} /></Field><label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} />Confirmo que solicito al Directorio el pronunciamiento de la próxima Asamblea sobre mi solicitud de ingreso.</label></fieldset>
    {error && <Aviso tipo="error">{error}</Aviso>}<button className={botonPrimario} disabled={ocupado || !confirmado || !motivo.trim()}>{ocupado ? 'Enviando…' : incierto ? 'Reintentar la misma apelación' : 'Solicitar apelación'}</button>
  </form>
}

function EnviarSubsanacion({ solicitud, credenciales, actualizar }: { solicitud: Subsanacion; credenciales: CredencialesExpediente; actualizar: () => Promise<void> }) {
  const [archivos, setArchivos] = useState<Record<string, File>>({}), [confirmado, setConfirmado] = useState(false), [ocupado, setOcupado] = useState(false), [incierto, setIncierto] = useState(false), [error, setError] = useState('')
  const bloqueo = useRef(false)
  const elegir = (kind: string, file?: File) => {
    if (!file) { setArchivos(v => { const n = { ...v }; delete n[kind]; return n }); return }
    const valido = file.size > 0 && file.size <= MAX_ARCHIVO_BYTES && TIPOS_ARCHIVO.includes(file.type) && /\.(pdf|jpe?g|png)$/i.test(file.name)
    setError(valido ? '' : 'Usa PDF, JPG o PNG con contenido y hasta 5 MB por archivo.')
    setArchivos(v => { const n = { ...v }; if (valido) n[kind] = file; else delete n[kind]; return n })
  }
  const enviar = async (ev: FormEvent) => {
    ev.preventDefault()
    if (bloqueo.current || !confirmado || !solicitud.requested_kinds.every(k => archivos[k])) return
    bloqueo.current = true; setOcupado(true); setError('')
    const form = new FormData()
    Object.entries({ ...credenciales, correction_id: solicitud.id, confirmation: '1' }).forEach(([k,v]) => form.set(k,v))
    Object.entries(archivos).forEach(([k,v]) => form.set(`documents[${k}]`,v))
    try { leerGestion(await solicitudJson('/api/v1/applications/corrections/submit', { method: 'POST', credentials: 'omit', body: form })); await actualizar() }
    catch (e) { setIncierto(e instanceof ApiError && e.incierto); setError(e instanceof ApiError ? e.message : 'Consulta el expediente antes de volver a enviar.') }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  return <form onSubmit={enviar} className="space-y-4"><p>Se conservarán los documentos anteriores. Adjunta todas las nuevas versiones solicitadas en este envío.</p>
    <fieldset disabled={ocupado || incierto} className="space-y-4">{solicitud.requested_kinds.map(k => <Field key={k} id={`subsanar-${k}`} label={NOMBRES_DOCUMENTOS[k] ?? k}><input id={`subsanar-${k}`} type="file" accept=".pdf,.jpg,.jpeg,.png" className="block w-full min-w-0 rounded border border-line p-2" onChange={e => elegir(k,e.target.files?.[0])} required /></Field>)}<label className="flex items-start gap-3"><input type="checkbox" className="mt-1" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} />Confirmo el envío de estas versiones para su revisión.</label></fieldset>
    {error && <Aviso tipo="error">{error}</Aviso>}<button className={botonPrimario} disabled={ocupado || !confirmado || !solicitud.requested_kinds.every(k => archivos[k])}>{ocupado ? 'Enviando…' : incierto ? 'Reintentar los mismos documentos' : 'Enviar subsanación'}</button>
  </form>
}
