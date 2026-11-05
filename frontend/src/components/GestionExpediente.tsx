import { useRef, useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import { NOMBRES_DOCUMENTOS, type Gestion } from '../lib/expediente'
import { peticionPrivada, type Sesion } from '../lib/httpPrivado'
import { fechaLarga } from '../lib/formato'
import { Aviso, Field } from './ui'
import { botonPrimario, inputCls } from './estilos'

export default function GestionExpediente({ id, legal, version, estado, gestion, sesion, actualizar }: { id: string; legal: string; version: string; estado: string; gestion: Gestion; sesion: Sesion; actualizar: () => Promise<void> }) {
  const abierta = gestion.corrections.some(c => ['waiting_applicant', 'submitted'].includes(c.status))
  return <div className="mt-6 space-y-6 border-t border-line pt-5">
    {gestion.corrections.map((c, i) => <section key={c.id} className="space-y-3 text-sm"><h3 className="text-xl font-bold text-navy">Subsanación {i+1}</h3><p>{fechaLarga(c.requested_at)} · {c.status === 'waiting_applicant' ? 'Pendiente del postulante' : c.status === 'submitted' ? 'Pendiente de revisión' : c.status === 'accepted' ? 'Aceptada' : 'Observada'}</p><p className="whitespace-pre-wrap break-words">{c.reason}</p><p>{c.requested_kinds.map(k => NOMBRES_DOCUMENTOS[k] ?? k).join(', ')}</p>
      <ul className="space-y-2">{c.documents.map(d => <li key={d.id}><a className="break-all text-navy underline" href={`/api/v1/admin/applications/${id}/revisions/${d.id}`}>{d.original_name}</a> · Versión {d.version_no}</li>)}</ul>
      {c.review && <p className="whitespace-pre-wrap break-words">Resultado de revisión: {c.review.reason}</p>}
      {c.status === 'submitted' && sesion.can_manage_corrections && <Accion key={c.id} titulo="Revisar nuevas versiones" ruta={`/api/v1/admin/applications/${id}/corrections/${c.id}/review`} boton="Registrar revisión de documentos" actualizar={actualizar} campos={[
        { name: 'outcome', label: 'Resultado documental', options: [['accepted','Aceptar documentos'],['needs_changes','Requiere otra subsanación']] },
        { name: 'reason', label: 'Fundamento de la revisión', textarea: true },
      ]} confirmacion="Confirmo que revisé estas versiones. El resultado quedará conservado." />}
    </section>)}
    {estado === 'under_review' && sesion.can_manage_corrections && !abierta && version !== '2026-10-28-pilot-2' && <SolicitarDocumentos key={`${id}-${gestion.corrections.length}`} id={id} legal={legal} version={version} actualizar={actualizar} />}
    {abierta && <Aviso>La subsanación debe terminar su revisión antes de registrar una resolución del Directorio.</Aviso>}
    {gestion.appeal && <section className="space-y-3 text-sm"><h3 className="text-xl font-bold text-navy">Apelación ante el Directorio</h3><p>Solicitud del {fechaLarga(gestion.appeal.requested_at)}</p><p className="whitespace-pre-wrap break-words">{gestion.appeal.reason}</p>
      {gestion.appeal.resolution ? <div className="space-y-2"><h4 className="font-bold">Resolución de la Asamblea</h4><p>{gestion.appeal.resolution.outcome === 'approved' ? 'Aprobación de ingreso' : 'Rechazo confirmado'} · {gestion.appeal.resolution.meeting_date}</p><p className="break-words">Referencia: {gestion.appeal.resolution.minutes_reference}</p><p className="whitespace-pre-wrap break-words">{gestion.appeal.resolution.reason}</p></div>
        : sesion.can_record_appeal ? <Accion titulo="Registrar acuerdo de la Asamblea" ruta={`/api/v1/admin/applications/${id}/appeal-resolution`} boton="Registrar resolución de apelación" actualizar={actualizar} campos={[
          { name: 'outcome', label: 'Resultado de la apelación', options: [['approved','Aprobar ingreso'],['rejected','Confirmar rechazo']] },
          { name: 'meeting_type', label: 'Tipo de Asamblea', options: [['ordinary','Ordinaria'],['extraordinary','Extraordinaria']] },
          { name: 'meeting_date', label: 'Fecha de la Asamblea', type: 'date' }, { name: 'minutes_reference', label: 'Referencia de acta', max: 255 },
          { name: 'reason', label: 'Fundamento del acuerdo de la Asamblea', textarea: true },
        ]} confirmacion="Confirmo que registro el acuerdo adoptado por la próxima Asamblea que conoció esta apelación. El rechazo inicial se conserva." /> : <Aviso>Pendiente del pronunciamiento de la próxima Asamblea.</Aviso>}
    </section>}
    {gestion.membership ? <section className="space-y-2 text-sm"><h3 className="text-xl font-bold text-navy">Ingreso registrado</h3><p>Fecha efectiva: {gestion.membership.effective_date}</p><p className="break-words">Registro: {gestion.membership.register_reference}</p><p>La habilitación de cuentas y asignaciones se gestiona por separado.</p></section>
      : gestion.effective_outcome === 'approved' && sesion.can_formalize && <section>{gestion.formalization_enabled ? <Accion titulo="Registrar ingreso como socio" ruta={`/api/v1/admin/applications/${id}/formalize`} boton="Registrar ingreso" actualizar={actualizar} campos={[
        { name: 'register_reference', label: 'Referencia en el registro institucional de socios', max: 255 }, { name: 'effective_date', label: 'Fecha efectiva de ingreso', type: 'date' },
      ]} confirmacion="Confirmo la identidad, la aprobación y el cumplimiento de las comprobaciones institucionales para registrar este ingreso. La habilitación de acceso se gestiona por separado." /> : <Aviso>La solicitud está aprobada. El registro de ingreso requiere habilitación institucional expresa.</Aviso>}</section>}
  </div>
}

function SolicitarDocumentos({ id, legal, version, actualizar }: { id: string; legal: string; version: string; actualizar: () => Promise<void> }) {
  const [kinds, setKinds] = useState<string[]>([]), [reason, setReason] = useState(''), [ocupado, setOcupado] = useState(false), [incierto, setIncierto] = useState(false), [error, setError] = useState('')
  const clave = useRef<string | null>(null), bloqueo = useRef(false)
  const permitidos = legal === 'natural' ? ['antecedentes'] : version === '2026-10-29-staged-3' ? ['estatutos','vigencia'] : ['estatutos','vigencia','personeria']
  const enviar = async (ev: FormEvent) => {
    ev.preventDefault()
    if (bloqueo.current || !kinds.length || !reason.trim()) return
    bloqueo.current = true; setOcupado(true); setError(''); clave.current ??= crypto.randomUUID()
    try {
      await peticionPrivada(`/api/v1/admin/applications/${id}/corrections`, { reason: reason.trim(), requested_kinds: kinds, operation_key: clave.current }, { 'Idempotency-Key': clave.current })
      await actualizar()
    } catch (e) { setIncierto(e instanceof ApiError && e.incierto); setError(e instanceof ApiError ? e.message : 'Comprueba el expediente antes de cambiar esta solicitud.') }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  return <form onSubmit={enviar} className="space-y-4"><h3 className="text-xl font-bold text-navy">Solicitar subsanación</h3><fieldset disabled={ocupado || incierto} className="space-y-4"><legend className="mb-2 text-sm font-semibold">Documentos que requieren una nueva versión</legend>{permitidos.map(k => <label key={k} className="flex items-start gap-3 text-sm"><input type="checkbox" checked={kinds.includes(k)} onChange={e => setKinds(v => e.target.checked ? [...v,k] : v.filter(x => x !== k))} />{NOMBRES_DOCUMENTOS[k]}</label>)}<Field id="observacion-documentos" label="Qué debe corregir el postulante"><textarea id="observacion-documentos" rows={4} maxLength={10000} value={reason} onChange={e => setReason(e.target.value)} required className={inputCls(false)} /></Field></fieldset>{error && <Aviso tipo="error">{error}</Aviso>}<button className={botonPrimario} disabled={ocupado || !kinds.length || !reason.trim()}>{ocupado ? 'Registrando…' : incierto ? 'Reintentar la misma solicitud' : 'Solicitar documentos corregidos'}</button></form>
}

type Campo = { name: string; label: string; type?: string; max?: number; textarea?: boolean; options?: [string,string][] }
function Accion({ titulo, ruta, boton, campos, confirmacion, actualizar }: { titulo: string; ruta: string; boton: string; campos: Campo[]; confirmacion: string; actualizar: () => Promise<void> }) {
  const [valores, setValores] = useState<Record<string,string>>({}), [confirmado, setConfirmado] = useState(false), [ocupado, setOcupado] = useState(false), [incierto, setIncierto] = useState(false), [error, setError] = useState('')
  const bloqueo = useRef(false)
  const enviar = async (ev: FormEvent) => {
    ev.preventDefault()
    if (bloqueo.current || !confirmado || campos.some(c => !valores[c.name]?.trim())) return
    bloqueo.current = true; setOcupado(true); setError('')
    try { await peticionPrivada(ruta, { ...Object.fromEntries(Object.entries(valores).map(([k,v]) => [k,v.trim()])), confirmation: true }); await actualizar() }
    catch (e) { setIncierto(e instanceof ApiError && e.incierto); setError(e instanceof ApiError ? e.status === 422 ? 'Revisa los campos, las fechas y el acuerdo que estás registrando.' : e.message : 'Consulta el expediente antes de cambiar el acuerdo.') }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  return <form onSubmit={enviar} className="space-y-4"><h3 className="text-xl font-bold text-navy">{titulo}</h3><fieldset disabled={ocupado || incierto} className="space-y-4">{campos.map(c => {
    const id = `${ruta.split('/').filter(Boolean).at(-1)}-${c.name}`
    const props = { id, value: valores[c.name] ?? '', onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setValores(v => ({ ...v, [c.name]: e.target.value })), required: true, className: inputCls(false) }
    return <Field key={c.name} id={id} label={c.label}>{c.options ? <select {...props}><option value="">Selecciona</option>{c.options.map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select> : c.textarea ? <textarea {...props} rows={4} maxLength={c.max ?? 10000} /> : <input {...props} type={c.type ?? 'text'} maxLength={c.max ?? 255} />}</Field>
  })}<label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} />{confirmacion}</label></fieldset>{error && <Aviso tipo="error">{error}</Aviso>}<button className={botonPrimario} disabled={ocupado || !confirmado || campos.some(c => !valores[c.name]?.trim())}>{ocupado ? 'Registrando…' : incierto ? 'Reintentar el mismo registro' : boton}</button></form>
}
