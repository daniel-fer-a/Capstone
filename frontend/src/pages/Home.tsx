import { Link } from 'react-router'
import { botonPrimario, botonSecundario } from '../components/ui'

const PUNTOS = [
  ['Presenta tus antecedentes', 'Datos y documentos según tu modalidad.'],
  ['Revisión con constancia', 'Evaluación y resolución del Directorio.'],
  ['Acompaña tu solicitud', 'Seguimiento y acceso al resultado.'],
]

export default function Home() {
  return (
    <div className="mx-auto max-w-5xl px-5 py-14">
      <div className="grid items-center gap-10 md:grid-cols-2">
        <div>
          <span className="inline-block rounded-full bg-tint px-3 py-1 text-xs font-bold text-teal">
            Ingreso de socios
          </span>
          <h1 className="mt-4 text-5xl font-extrabold leading-tight text-navy">
            Tu experiencia en IA, en comunidad.
          </h1>
          <p className="mt-5 text-lg text-muted">
            Presenta tu postulación a la Cámara Chilena de Inteligencia Artificial. Comparte tus
            antecedentes y acompaña cada etapa del proceso.
          </p>
          <div className="mt-7 flex flex-wrap gap-3">
            <Link to="/postular" className={botonPrimario}>Postular como socio →</Link>
            <Link to="/seguimiento" className={botonSecundario}>Consultar postulación</Link>
          </div>
          <p className="mt-4 text-xs text-muted">
            La postulación es pública. El acceso al área institucional se realiza por separado.
          </p>
        </div>
        <ul className="space-y-3 rounded-2xl bg-tint p-5">
          {PUNTOS.map(([titulo, texto]) => (
            <li key={titulo} className="rounded-lg bg-white p-4 shadow-sm">
              <p className="font-bold text-navy">{titulo}</p>
              <p className="text-sm text-muted">{texto}</p>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
