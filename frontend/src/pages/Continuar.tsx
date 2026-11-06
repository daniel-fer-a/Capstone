import { useRef, useState } from 'react'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, inputCls } from '../components/estilos'
import { ApiError } from '../lib/api'
import { retomarSolicitud } from '../lib/patrocinios'
import { usePostulacion } from '../postulacion/postulacionContext'
import { PanelPatrocinios } from './Patrocinios'

export default function Continuar() {
  const { previa, setPrevia } = usePostulacion()
  const [folio, setFolio] = useState('')
  const [codigo, setCodigo] = useState('')
  const [error, setError] = useState('')
  const [ocupado, setOcupado] = useState(false)
  const [otra, setOtra] = useState(false)
  const bloqueo = useRef(false)
  if (previa && !otra) return <><PanelPatrocinios /><div className="mx-auto mb-8 w-full max-w-5xl px-5"><button type="button" className="text-sm text-navy underline" onClick={() => setOtra(true)}>Consultar otra solicitud con su folio y código</button></div></>
  return <Pagina migas="Retomar" etiqueta="Acceso privado a tu solicitud" titulo="Retoma tu postulación"
    bajada="Usa el folio privado de patrocinios y su código de acceso. No necesitas una cuenta para continuar.">
    <form className="max-w-xl space-y-5 rounded-xl border border-line bg-white p-6" onSubmit={async ev => {
      ev.preventDefault()
      if (bloqueo.current) return
      if (!/^CCHIA-PRE-[A-Z0-9]{26}$/.test(folio.trim()) || !/^[A-Za-z0-9]{64}$/.test(codigo.trim())) { setError('Revisa el folio privado y el código de 64 caracteres.'); return }
      bloqueo.current = true; setOcupado(true); setError('')
      try { setPrevia(await retomarSolicitud(folio, codigo)); setOtra(false) }
      catch (e) { setError(e instanceof ApiError ? e.message : 'No pudimos retomar la solicitud.') }
      finally { bloqueo.current = false; setOcupado(false) }
    }}>
      <Field id="folio-privado" label="Folio privado de patrocinios" hint="Comienza con CCHIA-PRE-."><input id="folio-privado" value={folio} onChange={ev => setFolio(ev.target.value)} className={inputCls(false)} maxLength={40} autoComplete="off" /></Field>
      <Field id="codigo-privado" label="Código de acceso" hint="Lo encontrarás en el archivo de acceso privado que descargaste."><input id="codigo-privado" type="password" value={codigo} onChange={ev => setCodigo(ev.target.value)} className={inputCls(false)} maxLength={64} autoComplete="off" /></Field>
      {error && <Aviso tipo="error">{error}</Aviso>}<button disabled={ocupado} className={botonPrimario}>{ocupado ? 'Consultando…' : 'Retomar solicitud'}</button>
    </form>
  </Pagina>
}
