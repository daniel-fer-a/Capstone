import { useEffect, useState, type ChangeEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { Aviso, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario } from '../components/estilos'
import { MAX_ARCHIVO_BYTES, TIPOS_ARCHIVO, tamano } from '../lib/formato'
import { retomarSolicitud } from '../lib/patrocinios'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function Documentos() {
  const navigate = useNavigate()
  const { previa, setPrevia, estatutos, setEstatutos, vigencia, setVigencia, respaldo, setRespaldo } = usePostulacion()
  const [errores, setErrores] = useState<Record<string, string>>({})
  const [comprobando, setComprobando] = useState(true)
  const [fallo, setFallo] = useState('')
  const [consulta, setConsulta] = useState(0)
  const reference = previa?.reference
  const codigo = previa?.codigo
  useEffect(() => {
    let activo = true
    if (reference && codigo) retomarSolicitud(reference, codigo).then(p => { if (activo) { setPrevia(p); setFallo(''); setComprobando(false) } })
      .catch(() => { if (activo) { setComprobando(false); setFallo('No pudimos comprobar los patrocinios. Vuelve a consultar antes de continuar.') } })
    return () => { activo = false }
  }, [reference, codigo, setPrevia, consulta])
  if (!previa) return <Navigate to="/continuar" replace />
  if (previa.application) return <Navigate to="/continuar" replace />
  const juridica = previa.legal === 'juridica'
  const campos = juridica ? [{ key: 'estatutos', label: 'Estatutos', file: estatutos, set: setEstatutos }, { key: 'vigencia', label: 'Certificado de vigencia', file: vigencia, set: setVigencia }]
    : [{ key: 'respaldo', label: 'Respaldo de actividad en IA (opcional)', file: respaldo, set: setRespaldo }]
  const elegir = (ev: ChangeEvent<HTMLInputElement>, campo: typeof campos[number]) => {
    const f = ev.target.files?.[0] ?? null
    if (!f) return
    const mensaje = !TIPOS_ARCHIVO.includes(f.type) || !/\.(pdf|jpe?g|png)$/i.test(f.name) ? 'Usa un archivo PDF, JPG o PNG.'
      : f.size === 0 || f.size > MAX_ARCHIVO_BYTES ? 'El archivo debe tener contenido y no superar los 5 MB.' : ''
    setErrores(v => ({ ...v, [campo.key]: mensaje }))
    campo.set(mensaje ? null : f)
    if (mensaje) ev.target.value = ''
  }
  return <Pagina migas="Postulación / Documentos" etiqueta={juridica ? 'Persona jurídica' : 'Persona natural'} titulo="Adjunta tus documentos"
    bajada={juridica ? 'Se requieren dos documentos: estatutos y certificado de vigencia. Los datos del representante ya están registrados.' : 'Puedes adjuntar un respaldo de tu actividad. Es opcional.'}>
    <div className="flex flex-col gap-6 md:flex-row"><Pasos actual={3} /><section className="min-w-0 flex-1 space-y-5 rounded-xl border border-line bg-white p-6">
      {comprobando ? <p role="status">Comprobando patrocinios…</p> : fallo ? <Aviso tipo="error">{fallo} <button type="button" className="underline" onClick={() => { setComprobando(true); setConsulta(v => v + 1) }}>Volver a comprobar</button></Aviso> : !previa.ready_for_documents ? <Aviso tipo="warn">Faltan dos confirmaciones vigentes. <Link className="underline" to="/postular/patrocinios">Consultar patrocinios</Link></Aviso> : <>
        <p className="text-sm text-muted">PDF, JPG o PNG. Máximo 5 MB por documento.</p>
        {campos.map(c => <div key={c.key}><label htmlFor={c.key} className="mb-2 block text-sm font-bold">{c.label}</label><input id={c.key} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onChange={ev => elegir(ev, c)} aria-invalid={!!errores[c.key]} aria-describedby={errores[c.key] ? `${c.key}-error` : undefined} className="block w-full rounded border border-line p-2 text-sm" />{errores[c.key] && <p id={`${c.key}-error`} role="alert" className="mt-2 text-sm text-danger">{errores[c.key]}</p>}{c.file && <p className="mt-2 break-all text-sm text-ok">{c.file.name} · {tamano(c.file.size)} <button type="button" className="ml-2 text-navy underline" onClick={() => { c.set(null); setErrores(v => ({ ...v, [c.key]: '' })); const input = document.getElementById(c.key) as HTMLInputElement; input.value = '' }}>Quitar</button></p>}{errores[c.key] && !juridica && <button type="button" className="mt-2 text-sm text-navy underline" onClick={() => { c.set(null); setErrores({}) }}>Continuar sin respaldo</button>}</div>)}
        <Aviso>Los documentos solo estarán disponibles para las personas autorizadas a revisar la postulación.</Aviso>
        <div className="flex flex-wrap justify-end gap-3"><Link className={botonSecundario} to="/postular/patrocinios">Volver</Link><button type="button" className={botonPrimario} disabled={Object.values(errores).some(Boolean) || (juridica && (!estatutos || !vigencia))} onClick={() => navigate('/postular/revision')}>Revisar postulación →</button></div>
      </>}
    </section></div>
  </Pagina>
}
