import IntakeNotice from '../components/IntakeNotice'
import { Link, useNavigate } from 'react-router'
import { Aviso, Pagina } from '../components/ui'
import { usePostulacion } from '../postulacion/postulacionContext'

export default function Modalidad() {
  const navigate = useNavigate()
  const { reiniciar } = usePostulacion()

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
      <div className="grid gap-5 md:grid-cols-2">
        <button
          type="button"
          onClick={() => empezar('/postular/natural/datos')}
          className="rounded-xl border border-line bg-white p-6 text-left hover:border-navy"
        >
          <h2 className="text-2xl font-bold text-navy">Persona natural</h2>
          <p className="mt-2 text-sm text-muted">
            Presentas tus antecedentes personales y la actividad de inteligencia artificial que realizas.
          </p>
          <p className="mt-5 text-sm font-bold text-navy">Postular como persona natural →</p>
        </button>
        <Link
          to="/postular/juridica"
          className="rounded-xl border border-line bg-white p-6 hover:border-navy"
        >
          <h2 className="text-2xl font-bold text-navy">Persona jurídica</h2>
          <p className="mt-2 text-sm text-muted">
            Representas una organización y adjuntas los documentos que acreditan su vigencia y representación.
          </p>
          <p className="mt-5 text-sm font-bold text-navy">Postular como organización →</p>
        </Link>
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
