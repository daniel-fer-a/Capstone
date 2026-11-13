import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { ApiError } from '../lib/api'
import { crearSolicitud, renovarPatrocinadores, retomarSolicitud, validarCorreos } from '../lib/patrocinios'
import { fechaLarga } from '../lib/formato'
import { usePostulacion } from '../postulacion/postulacionContext'
import { nuevaClave } from '../lib/clave'

const ETIQUETAS: Record<string, string> = {
  pending: 'Pendiente de confirmación', accepted: 'Patrocinio confirmado', declined: 'Patrocinio rechazado',
  confirmation_unavailable: 'La confirmación requiere un socio habilitado',
}

export default function Patrocinios() {
  const navigate = useNavigate()
  const { datos, juridica, previa, setPrevia, claveEnvio } = usePostulacion()
  const [emails, setEmails] = useState(['', ''])
  const [consentimiento, setConsentimiento] = useState(false)
  const [ocupado, setOcupado] = useState(false)
  const [error, setError] = useState('')
  const [incierto, setIncierto] = useState(false)
  const [conflicto, setConflicto] = useState(false)
  const [espera, setEspera] = useState(0)
  const bloqueo = useRef(false)
  useEffect(() => {
    if (!espera) return
    const t = window.setTimeout(() => setEspera(v => v - 1), 1000)
    return () => window.clearTimeout(t)
  }, [espera])
  useEffect(() => {
    if (!incierto && !ocupado) return
    const aviso = (ev: BeforeUnloadEvent) => ev.preventDefault()
    window.addEventListener('beforeunload', aviso)
    return () => window.removeEventListener('beforeunload', aviso)
  }, [incierto, ocupado])
  if (previa) return <PanelPatrocinios />
  const comun = juridica ?? datos
  if (!comun) return <Navigate to="/postular" replace />
  const enviar = async (ev: React.FormEvent) => {
    ev.preventDefault()
    if (bloqueo.current || !consentimiento || espera || conflicto) return
    const fallo = validarCorreos(emails, comun.correo)
    if (fallo) { setError(fallo); return }
    bloqueo.current = true; setOcupado(true); setError('')
    try { setPrevia(await crearSolicitud(datos, juridica, emails, claveEnvio)); setIncierto(false) }
    catch (e) {
      setError(e instanceof ApiError ? Object.values(e.campos)[0] ?? e.message : 'No pudimos confirmar el registro. Reintenta el mismo envío.')
      setIncierto(!(e instanceof ApiError) || e.incierto || e.status === 409)
      if (e instanceof ApiError && e.status === 409) setConflicto(true)
      if (e instanceof ApiError && e.status === 429) setEspera(e.reintentarEnSegundos ?? 60)
    } finally { bloqueo.current = false; setOcupado(false) }
  }
  return <Pagina migas="Postulación / Patrocinios" etiqueta="Segundo paso" titulo="Solicita dos patrocinios"
    bajada="Indica los correos de dos socios que conozcas. Cada uno deberá responder desde su cuenta.">
    <div className="flex flex-col gap-6 md:flex-row"><Pasos actual={2} />
      <form onSubmit={enviar} noValidate className="min-w-0 flex-1 rounded-xl border border-line bg-white p-6" aria-busy={ocupado}>
        <h2 className="text-xl font-bold text-navy">Socios que te patrocinan</h2>
        <p className="my-4 text-sm text-muted">El sistema comprueba la habilitación de cada socio. Por privacidad, el registro no informa si un correo pertenece al padrón.</p>
        <fieldset disabled={ocupado || incierto} className="space-y-5">{emails.map((email, i) => <Field key={i} id={`socio-${i + 1}`} label={`Correo del socio ${i + 1}`} hint="Debe ser un socio distinto de quien presenta la solicitud.">
          <input id={`socio-${i + 1}`} type="email" maxLength={255} autoComplete="off" value={email} className={inputCls(false)} onChange={ev => setEmails(v => v.map((x, n) => n === i ? ev.target.value : x))} />
        </Field>)}
          <label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 h-4 w-4 shrink-0" checked={consentimiento} onChange={e => setConsentimiento(e.target.checked)} />Confirmo mis datos y autorizo compartir mi nombre o razón social, actividad en IA, región y comuna con los socios invitados para que decidan si patrocinan esta solicitud.</label>
        </fieldset>
        {error && <div className="mt-5"><Aviso tipo="error">{error}</Aviso></div>}
        {incierto && <p className="mt-3 text-sm text-muted">{conflicto ? 'Retoma la solicitud con el folio privado y el código que conservaste. Si no los tienes, verifica su registro con la CCHIA antes de comenzar otra.' : 'Reintenta este mismo envío para recuperar el folio y código. Evita recargar o modificar sus datos.'}</p>}
        {espera > 0 && <p className="mt-3 text-sm" role="status">Podrás reintentar en {espera} segundos.</p>}
        <div className="mt-6 flex flex-wrap justify-end gap-3"><button type="button" className={botonSecundario} disabled={ocupado || incierto} onClick={() => navigate(juridica ? '/postular/juridica' : '/postular/natural/datos')}>Volver a los datos</button>
          <button className={botonPrimario} disabled={ocupado || !consentimiento || espera > 0 || conflicto}>{ocupado ? 'Registrando…' : incierto ? 'Recuperar acceso privado' : 'Registrar solicitud de patrocinios'}</button></div>
      </form>
    </div>
  </Pagina>
}

export function PanelPatrocinios() {
  const { previa, setPrevia, setRespaldo, setEstatutos, setVigencia } = usePostulacion()
  const [ocupado, setOcupado] = useState(false)
  const [error, setError] = useState('')
  const [verCodigo, setVerCodigo] = useState(false)
  const [cambiar, setCambiar] = useState(false)
  const [emails, setEmails] = useState(() => previa?.invitations.map(i => i.email) ?? ['', ''])
  const [confirmado, setConfirmado] = useState(false)
  const [incierto, setIncierto] = useState(false)
  const [clave, setClave] = useState(() => nuevaClave())
  const bloqueo = useRef(false)
  useEffect(() => {
    const aviso = (ev: BeforeUnloadEvent) => ev.preventDefault()
    window.addEventListener('beforeunload', aviso)
    return () => window.removeEventListener('beforeunload', aviso)
  }, [])
  if (!previa) return <Navigate to="/continuar" replace />
  const actualizar = async () => {
    if (bloqueo.current) return
    bloqueo.current = true; setOcupado(true); setError('')
    try { setPrevia(await retomarSolicitud(previa.reference, previa.codigo)) }
    catch (e) { setError(e instanceof ApiError ? e.message : 'No pudimos consultar las confirmaciones.') }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  const renovar = async (ev: React.FormEvent) => {
    ev.preventDefault()
    if (bloqueo.current || !confirmado) return
    const fallo = validarCorreos(emails, String(previa.payload.email))
    if (fallo) { setError(fallo); return }
    bloqueo.current = true; setOcupado(true); setError('')
    try {
      setPrevia(await renovarPatrocinadores(previa, emails, clave))
      setRespaldo(null); setEstatutos(null); setVigencia(null)
      setCambiar(false); setConfirmado(false); setIncierto(false); setClave(nuevaClave())
    } catch (e) { setError(e instanceof ApiError ? Object.values(e.campos)[0] ?? e.message : 'No pudimos confirmar la renovación.'); setIncierto(!(e instanceof ApiError) || e.incierto) }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  const descargar = () => {
    const contenido = ['CCHIA · Acceso privado a la etapa de patrocinios', `Folio privado: ${previa.reference}`, `Código de acceso: ${previa.codigo}`, 'Continúa en la opción Retomar del sitio de la CCHIA.', 'Conserva este archivo en privado. Esta etapa no otorga membresía.'].join('\r\n')
    const url = URL.createObjectURL(new Blob([contenido], { type: 'text/plain;charset=utf-8' }))
    const a = document.createElement('a'); a.href = url; a.download = `acceso-${previa.reference}.txt`; a.click()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  }
  return <Pagina migas="Postulación / Patrocinios" etiqueta="Continuar postulación" titulo={previa.application ? 'Tu postulación fue enviada' : 'Tus patrocinios'}
    bajada="Conserva el folio privado y el código para retomar este proceso desde otro momento o dispositivo.">
    <div className="flex flex-col gap-6 md:flex-row"><Pasos actual={previa.application ? 5 : 2} />
      <section className="min-w-0 flex-1 space-y-5 rounded-xl border border-line bg-white p-5 sm:p-6" aria-busy={ocupado}>
        <div><p className="text-sm text-muted">Folio privado de patrocinios</p><p className="break-all font-mono text-sm font-bold">{previa.reference}</p><p className="mt-2 text-xs text-muted">Registrado el {fechaLarga(previa.created_at)}</p></div>
        <div className="rounded-lg border border-line p-4"><button type="button" className="text-sm font-bold text-navy underline" onClick={() => setVerCodigo(v => !v)}>{verCodigo ? 'Ocultar código de acceso' : 'Ver código de acceso'}</button>{verCodigo && <p className="mt-3 break-all font-mono text-sm">{previa.codigo}</p>}
          <div className="mt-3"><button type="button" className={botonSecundario} onClick={descargar}>Descargar acceso privado</button></div></div>
        <ol className="space-y-3">{previa.invitations.map(i => <li key={i.position} className="rounded-lg bg-tint p-4 text-sm"><p className="break-all font-bold">Socio {i.position} · {i.email}</p><p className="mt-1">{ETIQUETAS[i.state]}</p>{i.decided_at && <p className="mt-1 text-xs text-muted">{fechaLarga(i.decided_at)}</p>}</li>)}</ol>
        {previa.application ? <Aviso>Folio de postulación: <strong className="break-all">{previa.application.reference}</strong>. Estado: {previa.application.status === 'received' ? 'Recibida' : previa.application.status === 'under_review' ? 'En revisión' : previa.application.status === 'approved' ? 'Aprobada' : 'Rechazada'}. La recepción y el resultado no crean una cuenta ni asignan membresía automáticamente.</Aviso>
          : previa.ready_for_documents ? <Aviso>Los dos patrocinios están confirmados. Ya puedes continuar con los documentos y revisar tu postulación antes de enviarla.</Aviso>
          : <Aviso>Para continuar necesitamos dos confirmaciones de socios habilitados. Las invitaciones se consultan y responden desde sus cuentas. La documentación se habilita después.</Aviso>}
        {error && <Aviso tipo="error">{error}</Aviso>}
        <div className="flex flex-wrap gap-3"><button type="button" className={botonSecundario} disabled={ocupado || incierto} onClick={actualizar}>{ocupado ? 'Consultando…' : 'Actualizar estado'}</button>{previa.ready_for_documents && !previa.application && !cambiar && <Link className={botonPrimario} to="/postular/documentos">Continuar con documentos →</Link>}</div>
        {!previa.application && !cambiar && <button type="button" className="text-sm text-navy underline" onClick={() => { setEmails(previa.invitations.map(i => i.email)); setCambiar(true) }}>Corregir correos o renovar invitaciones</button>}
        {cambiar && <form onSubmit={renovar} className="space-y-4 border-t border-line pt-5"><h2 className="text-lg font-bold text-navy">Renovar los dos patrocinios</h2><p className="text-sm">Esta acción conserva el historial y solicita dos confirmaciones nuevas, incluso si mantienes los mismos correos.</p><fieldset disabled={ocupado || incierto} className="space-y-4">{emails.map((email, i) => <Field key={i} id={`renovar-${i}`} label={`Correo del socio ${i + 1}`}><input id={`renovar-${i}`} type="email" value={email} maxLength={255} className={inputCls(false)} onChange={ev => setEmails(v => v.map((x, n) => n === i ? ev.target.value : x))} /></Field>)}<label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 h-4 w-4 shrink-0" checked={confirmado} onChange={ev => setConfirmado(ev.target.checked)} />Entiendo que las dos confirmaciones anteriores dejarán de habilitar esta solicitud y se solicitarán nuevamente.</label></fieldset><div className="flex flex-wrap gap-3"><button className={botonPrimario} disabled={ocupado || !confirmado}>{incierto ? 'Confirmar la misma renovación' : 'Renovar invitaciones'}</button><button type="button" className={botonSecundario} disabled={ocupado || incierto} onClick={() => setCambiar(false)}>Cancelar</button></div></form>}
      </section>
    </div>
  </Pagina>
}
