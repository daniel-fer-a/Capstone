import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { GuardarWorkspace, PaginacionWorkspace, panelWorkspace, ResultadoAccion } from '../components/WorkspaceCommon'
import { useSesionInstitucional } from '../lib/useSesionInstitucional'
import { useWorkspaceAction } from '../lib/useWorkspaceAction'
import { esComision, leerOperacion, leerPaginaWorkspace, workspaceRequest, type Comision } from '../lib/workspace'

export default function Comisiones() {
  const { sesion, error: sessionError } = useSesionInstitucional(), action = useWorkspaceAction()
  const [rows, setRows] = useState<Comision[] | null>(null), [page, setPage] = useState(1), [last, setLast] = useState(1), [text, setText] = useState(''), [query, setQuery] = useState(''), [revision, setRevision] = useState(0), [error, setError] = useState('')
  const [name, setName] = useState(''), [description, setDescription] = useState('')
  useEffect(() => {
    let active = true
    if (!sesion?.workspace_enabled) return
    const params = new URLSearchParams({ page: String(page) }); if (query) params.set('q', query)
    workspaceRequest('/api/v1/workspace/commissions?' + params).then(c => { const p = leerPaginaWorkspace(c, esComision); if (active) { setRows(p.rows); setLast(p.last); setError('') } }).catch((e: unknown) => { if (active) setError(e instanceof Error ? e.message : 'No pudimos consultar las comisiones.') })
    return () => { active = false }
  }, [sesion?.workspace_enabled, page, query, revision])
  const create = async (e: FormEvent) => { e.preventDefault(); await action.run('/api/v1/workspace/commissions', { name, description }, leerOperacion, () => { setName(''); setDescription(''); setRevision(v => v + 1) }) }
  return <Pagina migas="Área privada / Comisiones" etiqueta="Colaboración" titulo="Mis comisiones" bajada="Accede a los proyectos, tareas y recursos de tus ámbitos de trabajo.">
    <Link className={botonSecundario} to="/area">Volver al área privada</Link>
    {(sessionError || error) && <div className="mt-5"><Aviso tipo="error">{sessionError || error}</Aviso></div>}
    {sesion && !sesion.workspace_enabled && <div className="mt-5"><Aviso>El espacio de colaboración todavía no se encuentra habilitado.</Aviso></div>}
    {sesion?.workspace_enabled && <>
      <form className={`${panelWorkspace} flex flex-wrap items-end gap-3`} onSubmit={e => { e.preventDefault(); setQuery(text.trim()); setPage(1) }}><div className="min-w-0 flex-1"><Field id="buscar-comision" label="Buscar por nombre"><input id="buscar-comision" maxLength={100} className={inputCls(false)} value={text} disabled={action.locked} onChange={e => setText(e.target.value)} /></Field></div><button className={botonSecundario} disabled={action.locked}>Buscar comisiones</button></form>
      {rows === null && !error && <p role="status">Consultando comisiones…</p>}
      {rows?.length === 0 && <Aviso>No tienes comisiones disponibles para este filtro. Una invitación debe aceptarse antes de obtener acceso.</Aviso>}
      <div className="grid gap-4 sm:grid-cols-2">{rows?.map(c => <article key={c.id} className="min-w-0 rounded-xl border border-line bg-white p-5"><p className="text-xs text-muted">{c.status === 'active' ? 'Activa' : 'Archivada'}</p><h2 className="mt-1 break-words text-xl font-bold text-navy">{c.name}</h2><p className="my-3 whitespace-pre-wrap break-words text-sm">{c.description || 'Sin descripción.'}</p><Link className={botonPrimario} to={`/area/comisiones/${c.id}`} onClick={e => { if (action.locked) e.preventDefault() }}>Abrir comisión</Link></article>)}</div>
      <PaginacionWorkspace page={page} last={last} change={setPage} disabled={action.locked} />
      {sesion.can_manage_commissions && <form onSubmit={create} className={panelWorkspace}><h2 className="text-xl font-bold text-navy">Crear comisión</h2><fieldset disabled={action.locked} className="space-y-4"><Field id="comision-nombre" label="Nombre de la comisión"><input id="comision-nombre" required maxLength={160} className={inputCls(false)} value={name} onChange={e => setName(e.target.value)} /></Field><Field id="comision-descripcion" label="Objetivo y descripción"><textarea id="comision-descripcion" maxLength={3000} rows={3} className={inputCls(false)} value={description} onChange={e => setDescription(e.target.value)} /></Field></fieldset><GuardarWorkspace action={action} url="/api/v1/workspace/commissions">Crear comisión</GuardarWorkspace></form>}
      <ResultadoAccion action={action} />
    </>}
  </Pagina>
}
