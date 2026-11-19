import IntakeNotice from '../components/IntakeNotice'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { Aviso, Pagina } from '../components/ui'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function Modalidad() {
  const navigate = useNavigate()
  const { reiniciar, previa, comprobante } = usePostulacion()
  const [guardado, setGuardado] = useState(false)
  const necesitaGuardar = !!previa || !!comprobante

  const empezar = (ruta: string) => {
    reiniciar()
    navigate(ruta)
  }

  return (
    <Pagina
      migas="Postulación"
      etiqueta="Ingreso de socios"
      titulo="¿Cómo quieres postular?"
      bajada="Selecciona la modalidad que corresponde a tu solicitud."
    >
      <IntakeNotice />
      {necesitaGuardar && <div className="mb-5"><Aviso tipo="warn"><p>Ya tienes un proceso en esta sesión. Conserva su acceso privado y su comprobante antes de comenzar otro.</p><label className="mt-3 flex items-start gap-3"><input type="checkbox" className="mt-1 h-4 w-4 shrink-0" checked={guardado} onChange={ev => setGuardado(ev.target.checked)} />Guardé los datos de acceso y quiero comenzar otra solicitud.</label></Aviso></div>}
      <div className="grid gap-5 md:grid-cols-2">
        <button
          type="button"
          disabled={necesitaGuardar && !guardado}
          onClick={() => empezar('/postular/natural/datos')}
          className="rounded-xl border border-line bg-white p-6 text-left hover:border-navy disabled:cursor-not-allowed disabled:opacity-55"
        >
          <h2 className="text-2xl font-bold text-navy">Persona natural</h2>
          <p className="mt-2 text-sm text-muted">
            Presentas tus antecedentes personales y la actividad de inteligencia artificial que realizas.
          </p>
          <p className="mt-5 text-sm font-bold text-navy">Postular como persona natural →</p>
        </button>
        <button
          type="button"
          disabled={necesitaGuardar && !guardado}
          onClick={() => empezar('/postular/juridica')}
          className="rounded-xl border border-line bg-white p-6 text-left hover:border-navy disabled:cursor-not-allowed disabled:opacity-55"
        >
          <h2 className="text-2xl font-bold text-navy">Persona jurídica</h2>
          <p className="mt-2 text-sm text-muted">
            Representas una organización y adjuntas los documentos que acreditan su vigencia y representación.
          </p>
          <p className="mt-5 text-sm font-bold text-navy">Postular como organización →</p>
        </button>
      </div>
      <div className="mt-5">
        <Aviso>
          En los siguientes pasos podrás revisar los antecedentes antes de enviarlos. El Directorio
          evalúa cada solicitud conforme al procedimiento institucional.
        </Aviso>
      </div>
    </Pagina>
  )
}
