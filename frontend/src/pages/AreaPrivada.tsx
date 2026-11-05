import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Aviso, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { ApiError, esObjeto } from '../lib/api'
import { peticionPrivada, leerSesion, type Sesion } from '../lib/httpPrivado'
import { fechaLarga } from '../lib/formato'
import { nombreEstado, ESTADOS } from '../lib/estados'
import GestionExpediente from '../components/GestionExpediente'
import { AVANCES, leerGestion, tituloGestion, type Gestion } from '../lib/expediente'
import RegistrarResolucion from '../components/RegistrarResolucion'

type Ficha = { workflow?: string; id: string; reference: string; status: string; legal: string; submitted_at: string; rules_version: string }
type Detalle = Ficha & {
  casework?: Gestion
  natural_name: string | null; natural_age: number | null; organization_name: string | null; email: string
  address: string; ai_activity: string
  documents: { id: string; original_name: string }[]
  sponsors: { id: string; position: number; confirmed_at: string | null; user: { name: string } | null }[]
  representative_name: string | null; representative_identifier: string | null; organization_identifier: string | null
  resolution: { outcome: string; reason: string; meeting_date: string; minutes_reference: string; recorded_at: string } | null
  events: { id: string; action: string; actor_user_id: string | null; actor: { name: string } | null; occurred_at: string }[]
}

export default function AreaPrivada() {
  const navigate = useNavigate()
  const [sesion, setSesion] = useState<Sesion | null>(null)
  const [error, setError] = useState('')
  const [saliendo, setSaliendo] = useState(false)
  useEffect(() => {
    let activo = true
    peticionPrivada('/api/v1/session/me').then((cuerpo) => { if (activo) setSesion(leerSesion(cuerpo)) })
      .catch((err: unknown) => { if (!activo) return; if (err instanceof ApiError && err.status === 401) navigate('/ingresar', { replace: true }); else setError('No pudimos comprobar la sesión. Vuelve a ingresar.') })
    return () => { activo = false }
  }, [navigate])
  const salir = async () => {
    setSaliendo(true)
    try { await peticionPrivada('/api/v1/session/logout', {}); setSesion(null); navigate('/ingresar', { replace: true }) }
    catch { setError('No pudimos confirmar el cierre de sesión. Inténtalo nuevamente.') }
    finally { setSaliendo(false) }
  }
  return <Pagina migas="Área privada" etiqueta="Acceso institucional" titulo={sesion?.can_review ? 'Revisión de postulaciones' : 'Área privada'}>
    {error && <div className="mb-5"><Aviso tipo="error">{error}</Aviso></div>}
    {!sesion && !error && <p role="status">Comprobando sesión…</p>}
    {sesion && <>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-white p-4"><p className="text-sm">Sesión de <strong>{sesion.name}</strong></p><button disabled={saliendo} className={botonSecundario} onClick={salir}>{saliendo ? 'Cerrando…' : 'Cerrar sesión'}</button></div>
      {sesion.can_read_members && <Link className={`${botonSecundario} mb-6 mr-3`} to="/area/socios">Consultar registro de socios →</Link>}
      {sesion.can_manage_notifications && <Link className={`${botonSecundario} mb-6 mr-3`} to="/area/avisos">Gestionar avisos →</Link>}
      {sesion.can_sponsor && <Link className={`${botonPrimario} mb-6`} to="/area/patrocinios">Ver solicitudes de patrocinio →</Link>}
      {sesion.can_review ? <Bandeja sesion={sesion} /> : <Aviso>{sesion.can_sponsor ? 'Puedes revisar y responder tus solicitudes de patrocinio desde el acceso anterior.' : 'Tu cuenta está identificada. Las funciones disponibles dependen de tus asignaciones vigentes.'}</Aviso>}
    </>}
  </Pagina>
}

function Bandeja({ sesion }: { sesion: Sesion }) {
  const navigate = useNavigate()
  const [estado, setEstado] = useState('')
  const [tarea, setTarea] = useState('')
  const [pagina, setPagina] = useState(1)
  const [ultima, setUltima] = useState(1)
  const [lista, setLista] = useState<Ficha[]>([])
  const [detalle, setDetalle] = useState<Detalle | null>(null)
  const [error, setError] = useState('')
  const [cargando, setCargando] = useState(false)
  const [accion, setAccion] = useState(false)
  const [recarga, setRecarga] = useState(0)
  useEffect(() => {
    let activo = true
    const parametros = new URLSearchParams({ page: String(pagina) })
    if (estado) parametros.set('status', estado)
    if (tarea) parametros.set('task', tarea)
    peticionPrivada(`/api/v1/admin/applications?${parametros}`).then((cuerpo) => {
      if (!activo) return
      if (!esObjeto(cuerpo) || !Array.isArray(cuerpo.data)) throw new Error('Respuesta inválida')
      setLista(cuerpo.data as Ficha[]); setUltima(Number(cuerpo.last_page) || 1); setError('')
    }).catch((err: unknown) => { if (!activo) return; setLista([]); if (err instanceof ApiError && err.status === 401) navigate('/ingresar', { replace: true }); else setError(err instanceof ApiError ? err.message : 'No pudimos cargar las postulaciones.') })
    return () => { activo = false }
  }, [estado, tarea, pagina, recarga, navigate])
  const abrir = async (id: string) => {
    setCargando(true); setDetalle(null); setError('')
    try {
      const cuerpo = await peticionPrivada(`/api/v1/admin/applications/${id}`)
      if (!esObjeto(cuerpo) || !esObjeto(cuerpo.data)) throw new Error('Respuesta inválida')
      if (cuerpo.data.casework !== undefined) leerGestion({ data: cuerpo.data.casework })
      setDetalle(cuerpo.data as Detalle)
    } catch (err) { setError(err instanceof ApiError ? err.message : 'No pudimos abrir el expediente.') }
    finally { setCargando(false) }
  }
  const iniciar = async () => {
    if (!detalle || accion) return
    setAccion(true)
    try {
      await peticionPrivada(`/api/v1/admin/applications/${detalle.id}/start-review`, {})
      await abrir(detalle.id); setRecarga((v) => v + 1)
    } catch (err) { setError(err instanceof ApiError ? err.message : 'No pudimos iniciar la revisión.') }
    finally { setAccion(false) }
  }
  return <>
    <label htmlFor="estado" className="mb-2 block text-sm font-bold">Filtrar por estado</label>
    <select id="estado" className={`${inputCls(false)} mb-5 max-w-xs`} value={estado} onChange={(ev) => { setEstado(ev.target.value); setPagina(1); setDetalle(null) }}><option value="">Todos los estados</option>{Object.entries(ESTADOS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
    <label htmlFor="tarea" className="mb-2 block text-sm font-bold">Gestión pendiente</label><select id="tarea" value={tarea} onChange={e => { setTarea(e.target.value); setPagina(1); setDetalle(null) }} className={`${inputCls(false)} mb-5 max-w-xs`}><option value="">Todas las gestiones</option><option value="pending_assembly">Apelaciones pendientes de Asamblea</option><option value="waiting_applicant">Documentos pendientes del postulante</option><option value="documents_submitted">Subsanaciones por revisar</option><option value="approved_unregistered">Ingresos aprobados por registrar</option></select>
    {error && <div className="mb-5"><Aviso tipo="error">{error}</Aviso></div>}
    <div className="overflow-x-auto rounded-xl border border-line bg-white"><table className="w-full text-left text-sm"><caption className="px-4 py-3 text-left font-bold text-navy">Bandeja de solicitudes</caption><thead className="border-y border-line bg-tint"><tr><th className="p-3">Folio y recepción</th><th className="p-3">Modalidad</th><th className="p-3">Estado</th><th className="p-3">Acción</th></tr></thead><tbody>{lista.map((f) => <tr key={f.id} className="border-b border-line last:border-0"><td className="p-3"><p className="break-all font-mono text-xs">{f.reference}</p><p className="mt-1 text-xs text-muted">{fechaLarga(f.submitted_at)}</p></td><td className="p-3">{f.legal === 'natural' ? 'Natural' : 'Jurídica'}</td><td className="p-3">{AVANCES[f.workflow ?? f.status] ?? nombreEstado(f.status)}</td><td className="p-3"><button disabled={cargando} className="text-navy underline" onClick={() => abrir(f.id)}>Ver expediente</button></td></tr>)}</tbody></table>{lista.length === 0 && <p className="p-4 text-sm text-muted">No hay solicitudes para mostrar con este filtro.</p>}</div>
    <div className="my-5 flex flex-wrap items-center justify-between gap-3 text-sm"><button className={botonSecundario} disabled={pagina <= 1} onClick={() => setPagina((p) => p - 1)}>Anterior</button><span>Página {pagina} de {ultima}</span><button className={botonSecundario} disabled={pagina >= ultima} onClick={() => setPagina((p) => p + 1)}>Siguiente</button></div>
    {cargando && <p role="status">Cargando expediente…</p>}
    {detalle && <section className="min-w-0 rounded-xl border border-line bg-white p-5 sm:p-6">
      <h2 className="text-2xl font-bold text-navy">Expediente de postulación</h2><p className="mt-2 break-all font-mono text-sm">{detalle.reference}</p><p className="mt-2 font-bold">{detalle.casework ? tituloGestion(detalle.casework, detalle.status) ?? nombreEstado(detalle.status) : nombreEstado(detalle.status)}</p>
      <dl className="mt-5 space-y-3 text-sm">{[
        ['Postulante', detalle.natural_name ?? detalle.organization_name],
        ...(detalle.legal === 'natural' ? [['Edad', detalle.natural_age === null ? 'Sin dato registrado' : String(detalle.natural_age)]] : []),
        ...(detalle.legal === 'juridica' ? [['RUT de la organización', detalle.organization_identifier], ['Representante', detalle.representative_name], ['RUT del representante', detalle.representative_identifier]] : []),
        ['Correo', detalle.email], ['Región y comuna', detalle.address], ['Actividad IA', detalle.ai_activity],
        ['Patrocinios registrados', String(detalle.sponsors.length)],
      ].map(([k,v]) => <div key={k}><dt className="text-xs text-muted">{k}</dt><dd className="whitespace-pre-wrap">{v}</dd></div>)}</dl>
      {detalle.rules_version === '2026-10-28-pilot-2' && <div className="mt-5"><Aviso tipo="warn">Este expediente corresponde a una prueba local y no acredita los patrocinios requeridos para el ingreso institucional.</Aviso></div>}
      <h3 className="mt-5 font-bold text-navy">Documentos privados</h3><ul className="mt-2 space-y-2 text-sm">{detalle.documents.length ? detalle.documents.map((doc) => <li key={doc.id}><a className="break-all text-navy underline" href={`/api/v1/admin/applications/${detalle.id}/documents/${doc.id}`}>{doc.original_name}</a></li>) : <li>Sin archivos adjuntos.</li>}</ul>
      <h3 className="mt-5 font-bold text-navy">Constancias de patrocinio</h3><ul className="mt-2 space-y-2 text-sm">{detalle.sponsors.map(s => <li key={s.id}>Socio {s.position}: {s.user?.name ?? 'Socio registrado'} · {s.confirmed_at ? `Confirmación del ${fechaLarga(s.confirmed_at)}` : 'Registro histórico de elegibilidad; no incluye confirmación en este flujo.'}</li>)}</ul>
      <h3 className="mt-5 font-bold text-navy">Trazabilidad</h3><ol className="mt-2 space-y-2 text-sm">{detalle.events.map((ev) => <li key={ev.id}>{ev.action === 'application.review_started' ? `Revisión iniciada por ${ev.actor?.name ?? 'el Directorio'}` : ev.action === 'application.submitted' ? 'Solicitud recibida' : ev.action === 'application.decision_recorded' ? `Resolución registrada por ${ev.actor?.name ?? 'una persona autorizada'}` : ({ 'application.correction_requested': 'Subsanación solicitada', 'application.correction_submitted': 'Nuevas versiones recibidas', 'application.correction_reviewed': 'Subsanación revisada', 'application.appeal_requested': 'Apelación solicitada', 'application.appeal_resolved': 'Acuerdo de la Asamblea registrado', 'application.membership_recorded': 'Ingreso registrado' } as Record<string,string>)[ev.action] ?? 'Evento del expediente'} · {fechaLarga(ev.occurred_at)}</li>)}</ol>
      {detalle.email.toLowerCase() !== sesion.email.toLowerCase() && detalle.status === 'received' && sesion.can_start_review && <button disabled={accion} onClick={iniciar} className={`${botonPrimario} mt-6`}>{accion ? 'Iniciando…' : 'Iniciar revisión'}</button>}
      {detalle.resolution && <div className="mt-6 space-y-2 border-t border-line pt-5 text-sm"><h3 className="font-bold text-navy">Resolución registrada</h3><p className="text-muted">Acuerdo inicial del Directorio</p><p>{nombreEstado(detalle.resolution.outcome)} · Sesión: {detalle.resolution.meeting_date.slice(0, 10)}</p><p className="break-words">Referencia: {detalle.resolution.minutes_reference}</p><p className="whitespace-pre-wrap break-words">{detalle.resolution.reason}</p></div>}
      {detalle.email.toLowerCase() !== sesion.email.toLowerCase() && detalle.status === 'under_review' && !detalle.casework?.corrections.some(c => ['waiting_applicant','submitted'].includes(c.status)) && sesion.can_record_decision && detalle.rules_version !== '2026-10-28-pilot-2' && <RegistrarResolucion key={`decision-${detalle.id}`} id={detalle.id} alRegistrar={async () => { await abrir(detalle.id); setRecarga(v => v + 1) }} />}
      {detalle.casework && <GestionExpediente key={`gestion-${detalle.id}`} id={detalle.id} legal={detalle.legal} version={detalle.rules_version} estado={detalle.status} gestion={detalle.casework} sesion={detalle.email.toLowerCase() === sesion.email.toLowerCase() ? { ...sesion, can_manage_corrections: false, can_record_appeal: false, can_formalize: false } : sesion} actualizar={async () => { await abrir(detalle.id); setRecarga(v => v+1) }} />}
    </section>}
  </>
}
