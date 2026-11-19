import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Aviso, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario } from '../components/estilos'
import { ApiError, esObjeto } from '../lib/api'
import { leerSesion, peticionPrivada } from '../lib/httpPrivado'
import { fechaLarga } from '../lib/formato'

type Invitacion = {
  id: string; legal: string; applicant_name: string; ai_activity: string; region: string; commune: string
  state: string; created_at: string; decided_at: string | null; submitted: boolean
}

export default function Invitaciones() {
  const navigate = useNavigate()
  const [lista, setLista] = useState<Invitacion[]>([])
  const [error, setError] = useState('')
  const [cargando, setCargando] = useState(true)
  const [habilitado, setHabilitado] = useState(false)
  const [accion, setAccion] = useState('')
  const [confirmaciones, setConfirmaciones] = useState<Record<string, boolean>>({})
  const [pagina, setPagina] = useState(1)
  const [ultima, setUltima] = useState(1)
  const [recarga, setRecarga] = useState(0)
  const bloqueo = useRef(false)
  useEffect(() => {
    let activo = true
    async function cargar() {
      try {
        const sesion = leerSesion(await peticionPrivada('/api/v1/session/me'))
        if (!activo) return
        setHabilitado(sesion.can_sponsor === true)
        if (sesion.can_sponsor) {
          const respuesta = await peticionPrivada(`/api/v1/sponsor/invitations?page=${pagina}`)
          if (!esObjeto(respuesta) || !Array.isArray(respuesta.data)) throw new Error('Respuesta inválida')
          if (activo) { setLista(respuesta.data as Invitacion[]); setUltima(Number(respuesta.last_page) || 1) }
        }
        if (activo) setError('')
      } catch (e) {
        if (!activo) return
        if (e instanceof ApiError && e.status === 401) navigate('/ingresar', { replace: true })
        else setError(e instanceof ApiError ? e.message : 'No pudimos cargar las invitaciones.')
      } finally { if (activo) setCargando(false) }
    }
    void cargar()
    return () => { activo = false }
  }, [pagina, recarga, navigate])
  const responder = async (id: string, decision: 'accepted' | 'declined') => {
    if (bloqueo.current || (decision === 'accepted' && !confirmaciones[id])) return
    bloqueo.current = true; setAccion(id); setError('')
    try {
      await peticionPrivada(`/api/v1/sponsor/invitations/${id}/decision`, { decision, ...(decision === 'accepted' ? { confirmation: true } : {}) })
      setRecarga(n => n + 1)
    } catch (e) { setError(e instanceof ApiError ? e.message : 'No pudimos confirmar la respuesta. Actualiza antes de intentar otra acción.') }
    finally { bloqueo.current = false; setAccion('') }
  }
  return <Pagina migas="Área privada / Patrocinios" etiqueta="Socios" titulo="Solicitudes de patrocinio"
    bajada="Revisa los antecedentes compartidos y responde expresamente cada invitación. Tu respuesta quedará registrada.">
    <Link to="/area" className={`${botonSecundario} mb-5`}>Volver al área privada</Link>
    {cargando && <p role="status">Consultando invitaciones…</p>}{error && <div className="mb-5"><Aviso tipo="error">{error}</Aviso></div>}
    {!cargando && !habilitado && !error && <Aviso>Para responder patrocinios necesitas una asignación de socio activa y vigente.</Aviso>}
    {habilitado && <>
      <button type="button" className={`${botonSecundario} mb-5`} disabled={!!accion} onClick={() => setRecarga(n => n + 1)}>Actualizar invitaciones</button>
      <div className="space-y-5">{lista.map(i => <section key={i.id} className="rounded-xl border border-line bg-white p-5 sm:p-6">
        <p className="text-xs text-muted">{i.legal === 'juridica' ? 'Persona jurídica' : 'Persona natural'} · {fechaLarga(i.created_at)}</p><h2 className="mt-2 break-words text-xl font-bold text-navy">{i.applicant_name}</h2>
        <p className="mt-2 text-sm">{i.region} · {i.commune}</p><p className="mt-4 whitespace-pre-wrap break-words text-sm">{i.ai_activity}</p>
        {i.state === 'pending' && !i.submitted ? <><label className="mt-5 flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 h-4 w-4 shrink-0" checked={!!confirmaciones[i.id]} disabled={!!accion} onChange={ev => setConfirmaciones(v => ({ ...v, [i.id]: ev.target.checked }))} />Confirmo que conozco a quien postula y acepto patrocinar esta solicitud de ingreso a la CCHIA.</label>
          <div className="mt-5 flex flex-wrap gap-3"><button type="button" className={botonPrimario} disabled={!!accion || !confirmaciones[i.id]} onClick={() => responder(i.id, 'accepted')}>{accion === i.id ? 'Registrando…' : 'Aceptar patrocinio'}</button><button type="button" className={botonSecundario} disabled={!!accion} onClick={() => responder(i.id, 'declined')}>Rechazar patrocinio</button></div></>
          : <p role="status" className="mt-5 font-bold text-navy">{i.state === 'accepted' ? 'Patrocinio aceptado' : i.state === 'declined' ? 'Patrocinio rechazado' : 'Solicitud enviada'}{i.decided_at ? ` · ${fechaLarga(i.decided_at)}` : ''}</p>}
      </section>)}</div>
      {!lista.length && <Aviso>No tienes invitaciones para mostrar.</Aviso>}
      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-sm"><button className={botonSecundario} disabled={pagina <= 1 || !!accion} onClick={() => setPagina(p => p - 1)}>Anterior</button><span>Página {pagina} de {ultima}</span><button className={botonSecundario} disabled={pagina >= ultima || !!accion} onClick={() => setPagina(p => p + 1)}>Siguiente</button></div>
    </>}
  </Pagina>
}
