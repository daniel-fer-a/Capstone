import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { ApiError } from '../lib/api'
import { peticionPrivada } from '../lib/httpPrivado'
import { fechaLarga } from '../lib/formato'
import { esAvisoCorreo, leerPagina, nombreEstadoAviso, type AvisoCorreo } from '../lib/operacion'
import { useSesionInstitucional } from '../lib/useSesionInstitucional'

const tipos: Record<string,string> = { sponsor_invitation: 'Invitación de patrocinio', application_received: 'Recepción de postulación', application_event: 'Actualización del expediente' }
export default function GestionAvisos() {
  const { sesion, error: errorSesion } = useSesionInstitucional()
  const [status, setStatus] = useState(''), [page, setPage] = useState(1), [last, setLast] = useState(1), [reload, setReload] = useState(0)
  const [rows, setRows] = useState<AvisoCorreo[]>([]), [error, setError] = useState(''), [seleccionado, setSeleccionado] = useState<AvisoCorreo | null>(null)
  const autorizado = !!sesion?.can_manage_notifications
  useEffect(() => {
    let activo = true
    if (!autorizado) return
    const query = new URLSearchParams({ page: String(page) }); if (status) query.set('status',status)
    peticionPrivada(`/api/v1/admin/notifications?${query}`).then(c => { const r = leerPagina(c,esAvisoCorreo); if (activo) { setRows(r.rows); setLast(r.last); setError('') } })
      .catch((e: unknown) => { if (activo) { setRows([]); setError(e instanceof ApiError ? e.message : 'No pudimos consultar los avisos.') } })
    return () => { activo = false }
  },[autorizado,status,page,reload])
  return <Pagina migas="Área privada / Avisos" etiqueta="Operación institucional" titulo="Gestión de avisos" bajada="Consulta el procesamiento de los avisos sin exponer destinatarios, documentos ni códigos privados.">
    <Link className={`${botonSecundario} mb-5`} to="/area">Volver al área privada</Link>
    {errorSesion && <Aviso tipo="error">{errorSesion}</Aviso>}{!sesion && !errorSesion && <p role="status">Comprobando sesión…</p>}{sesion && !autorizado && <Aviso tipo="error">Tu cuenta no tiene permiso para gestionar avisos.</Aviso>}
    {autorizado && <><div className="mb-5"><Aviso>Procesado significa que el transporte configurado aceptó el mensaje; no demuestra que la persona lo recibió o leyó. Log y array no envían correo real.</Aviso></div>
      <label htmlFor="estado-aviso" className="mb-2 block text-sm font-bold">Estado del aviso</label><select id="estado-aviso" className={`${inputCls(false)} mb-5 max-w-sm`} value={status} onChange={e => { setStatus(e.target.value); setPage(1); setSeleccionado(null) }}><option value="">Todos</option><option value="pending">Pendientes o en reintento</option><option value="failed">Intentos agotados por error</option><option value="sent">Procesados por el transporte</option><option value="omitted">Omitidos por estado o vigencia</option></select>
      <button className={`${botonSecundario} mb-5 ml-0 sm:ml-3`} onClick={() => { setSeleccionado(null); setReload(v => v+1) }}>Actualizar avisos</button>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <div className="overflow-x-auto rounded-xl border border-line bg-white"><table className="w-full text-left text-sm"><caption className="p-4 text-left font-bold text-navy">Procesamiento de avisos</caption><thead className="bg-tint"><tr><th className="p-3">Aviso y fecha</th><th className="p-3">Estado</th><th className="p-3">Intentos</th><th className="p-3">Acción</th></tr></thead><tbody>{rows.map(r => <tr key={r.id} className="border-t border-line"><td className="p-3">{tipos[r.kind]}<p className="mt-1 text-xs text-muted">{fechaLarga(r.created_at)}</p><p className="mt-1 break-all font-mono text-xs">{r.id}</p></td><td className="p-3">{nombreEstadoAviso(r)}</td><td className="p-3">{r.attempts}</td><td className="p-3">{r.can_retry ? <button className="text-navy underline" onClick={() => setSeleccionado(r)}>Preparar reintento</button> : 'Sin acción disponible'}</td></tr>)}</tbody></table>{!rows.length && <p className="p-4 text-sm">No hay avisos para este filtro.</p>}</div>
      <div className="my-5 flex items-center justify-between gap-2 text-sm"><button className={botonSecundario} disabled={page <= 1} onClick={() => { setPage(v => v-1); setSeleccionado(null) }}>Anterior</button><span>Página {page} de {last}</span><button className={botonSecundario} disabled={page >= last} onClick={() => { setPage(v => v+1); setSeleccionado(null) }}>Siguiente</button></div>
      {seleccionado && <Reintentar key={seleccionado.id} id={seleccionado.id} alCompletar={() => { setSeleccionado(null); setReload(v => v+1) }} />}
    </>}
  </Pagina>
}

function Reintentar({ id, alCompletar }: { id: string; alCompletar: () => void }) {
  const [reason, setReason] = useState(''), [confirmation, setConfirmation] = useState(false), [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(false), [error, setError] = useState('')
  const key = useRef<string | null>(null), lock = useRef(false)
  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    if (lock.current || !confirmation || reason.trim().length < 10) return
    lock.current = true; setBusy(true); setError(''); key.current ??= crypto.randomUUID()
    try { await peticionPrivada(`/api/v1/admin/notifications/${id}/retry`, { reason: reason.trim(), confirmation }, { 'Idempotency-Key': key.current }); alCompletar() }
    catch (err) { setUncertain(err instanceof ApiError && err.incierto); setError(err instanceof ApiError ? err.message : 'No pudimos confirmar la reactivación. Consulta el estado antes de cambiar el motivo.') }
    finally { lock.current = false; setBusy(false) }
  }
  return <form onSubmit={enviar} className="space-y-4 rounded-xl border border-line bg-white p-5"><h2 className="text-xl font-bold text-navy">Reactivar aviso agotado</h2><p className="break-all font-mono text-xs">{id}</p><p className="text-sm">Comprueba primero el problema de correo. La reactivación deja evidencia y vuelve a poner el aviso en cola. El proveedor podría haber aceptado un intento anterior.</p><fieldset disabled={busy || uncertain} className="space-y-4"><Field id="motivo-reintento" label="Motivo de la reactivación"><textarea id="motivo-reintento" rows={3} minLength={10} maxLength={500} value={reason} onChange={e => setReason(e.target.value)} className={inputCls(false)} required /></Field><label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1" checked={confirmation} onChange={e => setConfirmation(e.target.checked)} />Confirmo la revisión del error y solicito reactivar este aviso. Comprendo que el correo podría repetirse.</label></fieldset>{error && <Aviso tipo="error">{error}</Aviso>}<button className={botonPrimario} disabled={busy || !confirmation || reason.trim().length < 10}>{busy ? 'Reactivando…' : uncertain ? 'Recuperar la misma reactivación' : 'Reactivar aviso'}</button></form>
}
