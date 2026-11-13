import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { ApiError } from '../lib/api'
import { peticionPrivada } from '../lib/httpPrivado'
import { fechaLarga } from '../lib/formato'
import { esIngreso, leerDetalleIngreso, leerPagina, type DetalleIngreso, type Ingreso } from '../lib/operacion'
import { useSesionInstitucional } from '../lib/useSesionInstitucional'

export default function RegistroSocios() {
  const { sesion, error: errorSesion } = useSesionInstitucional()
  const [texto, setTexto] = useState(''), [q, setQ] = useState(''), [legal, setLegal] = useState(''), [page, setPage] = useState(1), [last, setLast] = useState(1)
  const [rows, setRows] = useState<Ingreso[]>([]), [detalle, setDetalle] = useState<DetalleIngreso | null>(null), [error, setError] = useState(''), [cargando, setCargando] = useState(false)
  const detalleActual = useRef(0)
  const autorizado = !!sesion?.can_read_members
  const params = new URLSearchParams({ page: String(page) }); if (q) params.set('q',q); if (legal) params.set('legal',legal)
  const consulta = params.toString()
  useEffect(() => {
    let activo = true
    if (!autorizado) return
    peticionPrivada(`/api/v1/admin/members?${consulta}`).then(c => { const p = leerPagina(c,esIngreso); if (activo) { setRows(p.rows); setLast(p.last); setError('') } })
      .catch((e: unknown) => { if (activo) { setRows([]); setError(e instanceof ApiError ? e.message : 'No pudimos consultar el registro.') } })
    return () => { activo = false }
  }, [autorizado, consulta])
  const abrir = async (id: string) => {
    const secuencia = ++detalleActual.current; setCargando(true); setDetalle(null); setError('')
    try { const d = leerDetalleIngreso(await peticionPrivada(`/api/v1/admin/members/${id}`)); if (secuencia === detalleActual.current) setDetalle(d) }
    catch (e) { if (secuencia === detalleActual.current) setError(e instanceof ApiError ? e.message : 'No pudimos consultar la ficha.') }
    finally { if (secuencia === detalleActual.current) setCargando(false) }
  }
  const filtrar = (e: FormEvent) => { e.preventDefault(); detalleActual.current++; setQ(texto.trim()); setPage(1); setDetalle(null); setCargando(false) }
  return <Pagina migas="Área privada / Registro de socios" etiqueta="Consulta institucional" titulo="Registro de ingresos como socios" bajada="Consulta los ingresos formalizados y sus antecedentes declarados. La habilitación de cuentas se gestiona por separado.">
    <Link className={`${botonSecundario} mb-5`} to="/area">Volver al área privada</Link>
    {errorSesion && <Aviso tipo="error">{errorSesion}</Aviso>}{!sesion && !errorSesion && <p role="status">Comprobando sesión…</p>}
    {sesion && !autorizado && <Aviso tipo="error">Tu cuenta no tiene permiso para consultar este registro.</Aviso>}
    {autorizado && <>
      <form onSubmit={filtrar} className="mb-5 flex flex-wrap items-end gap-4 rounded-xl border border-line bg-white p-5"><div className="min-w-0 flex-1"><Field id="buscar-socio" label="Nombre, razón social o referencia de registro"><input id="buscar-socio" className={inputCls(false)} value={texto} onChange={e => setTexto(e.target.value)} maxLength={100} /></Field></div><div><Field id="modalidad-registro" label="Modalidad"><select id="modalidad-registro" className={inputCls(false)} value={legal} onChange={e => { detalleActual.current++; setLegal(e.target.value); setPage(1); setDetalle(null); setCargando(false) }}><option value="">Todas</option><option value="natural">Persona natural</option><option value="juridica">Persona jurídica</option></select></Field></div><button className={botonPrimario}>Buscar en registro</button></form>
      {sesion?.can_export_members && <div className="mb-5 space-y-2 text-sm"><a className={botonSecundario} href={`/api/v1/admin/members/export?${consulta}`} download>Exportar registro filtrado CSV</a><p className="text-muted">El archivo contiene datos personales. Consérvalo únicamente en el canal institucional autorizado.</p></div>}
      {error && <div className="mb-5"><Aviso tipo="error">{error}</Aviso></div>}
      <div className="overflow-x-auto rounded-xl border border-line bg-white"><table className="w-full text-left text-sm"><caption className="p-4 text-left font-bold text-navy">Ingresos registrados</caption><thead className="bg-tint"><tr><th className="p-3">Registro</th><th className="p-3">Socio</th><th className="p-3">Ingreso</th><th className="p-3">Ficha</th></tr></thead><tbody>{rows.map(r => <tr key={r.id} className="border-t border-line"><td className="break-words p-3">{r.register_reference}</td><td className="break-words p-3">{r.natural_name ?? r.organization_name}<p className="text-xs text-muted">{r.legal === 'natural' ? 'Persona natural' : 'Persona jurídica'}</p></td><td className="p-3">{r.effective_date}</td><td className="p-3"><button className="text-navy underline" disabled={cargando} onClick={() => abrir(r.id)}>Consultar ficha</button></td></tr>)}</tbody></table>{!rows.length && <p className="p-4 text-sm">No hay ingresos para este filtro.</p>}</div>
      <div className="my-5 flex items-center justify-between gap-2 text-sm"><button className={botonSecundario} disabled={page <= 1} onClick={() => setPage(v => v-1)}>Anterior</button><span>Página {page} de {last}</span><button className={botonSecundario} disabled={page >= last} onClick={() => setPage(v => v+1)}>Siguiente</button></div>
      {cargando && <p role="status">Consultando ficha…</p>}
      {detalle && <section className="space-y-4 rounded-xl border border-line bg-white p-5"><h2 className="text-xl font-bold text-navy">Ficha del ingreso</h2><dl className="space-y-3 text-sm">{[
        ['Registro',detalle.register_reference],['Nombre o razón social',detalle.natural_name ?? detalle.organization_name],['Identificación',detalle.natural_identifier ?? detalle.organization_identifier],
        ...(detalle.legal === 'juridica' ? [['Representante',detalle.representative_name],['RUT del representante',detalle.representative_identifier]] : []),
        ['Correo declarado',detalle.email],['Domicilio declarado',detalle.address],['Actividad IA',detalle.ai_activity],['Fecha efectiva',detalle.effective_date],['Fecha de registro',fechaLarga(detalle.recorded_at)],['Folio de postulación',detalle.application_reference],
      ].map(([k,v]) => <div key={k}><dt className="text-xs text-muted">{k}</dt><dd className="whitespace-pre-wrap break-words">{v}</dd></div>)}</dl><Aviso>Esta consulta conserva los antecedentes del ingreso. No modifica el padrón ni concede facultades a una cuenta.</Aviso></section>}
    </>}
  </Pagina>
}
