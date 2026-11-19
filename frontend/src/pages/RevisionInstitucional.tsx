import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario } from '../components/estilos'
import { ApiError } from '../lib/api'
import { enviarSolicitud } from '../lib/patrocinios'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function RevisionInstitucional() {
  const navigate = useNavigate()
  const { previa, setPrevia, comprobante, respaldo, estatutos, vigencia, setComprobante, setRespaldo, setEstatutos, setVigencia } = usePostulacion()
  const [confirmado, setConfirmado] = useState(false)
  const [ocupado, setOcupado] = useState(false)
  const [incierto, setIncierto] = useState(false)
  const [error, setError] = useState('')
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
  if (!previa) return <Navigate to="/continuar" replace />
  if (comprobante?.solicitudPreviaId === previa.id) return <Navigate to="/postular/comprobante" replace />
  if (previa.application) return <Navigate to="/continuar" replace />
  if (!previa.ready_for_documents) return <Navigate to="/postular/patrocinios" replace />
  const juridica = previa.legal === 'juridica'
  if (juridica && (!estatutos || !vigencia)) return <Navigate to="/postular/documentos" replace />
  const d = previa.payload
  const filas: [string, string][] = [
    ['Modalidad', juridica ? 'Socio · Persona jurídica' : 'Socio · Persona natural'],
    ...(juridica ? [['Razón social', String(d.organizationName)], ['RUT de la organización', String(d.organizationId)], ['Representante', String(d.representativeName)], ['RUT del representante', String(d.representativeId)]] as [string, string][]
      : [['Nombres', String(d.givenNames)], ['Apellidos', String(d.familyNames)], ['Edad', String(d.age)], ['Identificación', String(d.identifier)]] as [string, string][]),
    ['Correo', String(d.email)], ['Región', String(d.region)], ['Comuna', String(d.commune)], ['Actividad en IA', String(d.aiActivity)],
    ...previa.invitations.map(i => [`Socio ${i.position}`, `${i.email} · Confirmado`] as [string, string]),
    ...(juridica ? [['Estatutos', estatutos!.name], ['Vigencia', vigencia!.name]] as [string, string][] : [['Respaldo', respaldo?.name ?? 'Sin respaldo adicional']] as [string, string][]),
  ]
  const enviar = async () => {
    if (bloqueo.current || !confirmado || conflicto || espera) return
    bloqueo.current = true; setOcupado(true); setError('')
    try {
      const recibida = await enviarSolicitud(previa, { respaldo, estatutos, vigencia })
      setComprobante({ ...recibida, solicitudPreviaId: previa.id })
      setPrevia({ ...previa, stage: 'submitted', application: { reference: recibida.folio, status: recibida.estado, submitted_at: recibida.recibidaEn } })
      setRespaldo(null); setEstatutos(null); setVigencia(null); setIncierto(false)
      navigate('/postular/comprobante', { replace: true })
    } catch (e) {
      setError(e instanceof ApiError ? Object.values(e.campos)[0] ?? e.message : 'No pudimos confirmar la recepción. Reintenta el mismo envío.')
      setIncierto(!(e instanceof ApiError) || e.incierto || e.status === 409)
      if (e instanceof ApiError && e.status === 409) setConflicto(true)
      if (e instanceof ApiError && e.status === 429) setEspera(e.reintentarEnSegundos ?? 60)
    } finally { bloqueo.current = false; setOcupado(false) }
  }
  return <Pagina migas="Postulación / Revisión" etiqueta="Antes de enviar" titulo="Revisa tu postulación" bajada="Los antecedentes que confirmaron los socios se mantienen vinculados a esta solicitud.">
    <div className="flex flex-col gap-6 md:flex-row"><Pasos actual={4} /><section className="min-w-0 flex-1 rounded-xl border border-line bg-white p-5 sm:p-6" aria-busy={ocupado}>
      <dl className="divide-y divide-line border-y border-line text-sm">{filas.map(([k, v]) => <div key={k} className="grid gap-1 py-3 sm:grid-cols-[180px_1fr]"><dt className="text-xs text-muted">{k}</dt><dd className="min-w-0 break-words whitespace-pre-wrap">{v}</dd></div>)}</dl>
      <div className="mt-5"><Aviso>El Directorio revisará la postulación. Los patrocinios y la recepción de antecedentes no otorgan membresía automática.</Aviso></div>
      <label className="mt-5 flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 h-4 w-4 shrink-0" checked={confirmado} disabled={ocupado || incierto} onChange={ev => setConfirmado(ev.target.checked)} />Confirmo que revisé los datos y documentos y que corresponden a esta postulación.</label>
      {error && <div className="mt-5"><Aviso tipo="error">{error}</Aviso></div>}{incierto && <p className="mt-3 text-sm">{conflicto ? 'Retoma tu solicitud para verificar si ya fue recibida. Conserva ambos comprobantes y contacta a la CCHIA si necesitas ayuda; evita iniciar otro registro.' : 'Puedes reintentar el mismo envío para recuperar su comprobante. Evita recargar o cambiar los archivos mientras no confirmes la recepción.'}</p>}
      {espera > 0 && <p className="mt-3 text-sm" role="status">Podrás reintentar en {espera} segundos.</p>}
      <div className="mt-6 flex flex-wrap justify-end gap-3"><button type="button" className={botonSecundario} disabled={ocupado || incierto} onClick={() => navigate('/postular/documentos')}>Volver a documentos</button><button type="button" className={botonPrimario} disabled={!confirmado || ocupado || conflicto || espera > 0} onClick={enviar}>{ocupado ? 'Enviando…' : conflicto ? 'Verificar recepción' : incierto ? 'Recuperar comprobante' : 'Enviar postulación →'}</button></div>
      {conflicto && <Link className="mt-4 inline-block text-sm text-navy underline" to="/continuar">Consultar mi solicitud privada</Link>}
    </section></div>
  </Pagina>
}
