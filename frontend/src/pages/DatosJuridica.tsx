import { useForm } from 'react-hook-form'
import { Navigate, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import IntakeNotice from '../components/IntakeNotice'
import { Field, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { usePostulacion, type DatosJuridica as Datos } from '../postulacion/postulacionContext'

export default function DatosJuridica() {
  const navigate = useNavigate()
  const { juridica, setJuridica, setDatos, previa } = usePostulacion()
  const { register, handleSubmit, formState: { errors } } = useForm<Datos>({ defaultValues: juridica ?? {
    razonSocial: '', rutOrganizacion: '', representante: '', rutRepresentante: '', correo: '', region: '', comuna: '', actividad: '',
  } })
  if (previa) return <Navigate to="/postular/patrocinios" replace />
  const campos: { key: keyof Datos; label: string; max: number; hint: string; type?: string }[] = [
    { key: 'razonSocial', label: 'Razón social', max: 255, hint: 'Nombre legal completo de la organización.' },
    { key: 'rutOrganizacion', label: 'RUT de la organización', max: 80, hint: 'Identificación de la persona jurídica.' },
    { key: 'representante', label: 'Nombre completo del representante', max: 255, hint: 'Nombre y apellidos de quien representa a la organización.' },
    { key: 'rutRepresentante', label: 'RUT del representante', max: 80, hint: 'RUT de la persona que representa a la organización.' },
    { key: 'correo', label: 'Correo electrónico de contacto', max: 255, hint: 'Correo al que tienes acceso.', type: 'email' },
    { key: 'region', label: 'Región', max: 120, hint: 'Región de la organización.' },
    { key: 'comuna', label: 'Comuna', max: 120, hint: 'Comuna de la organización.' },
  ]
  return <Pagina migas="Postulación / Socio" etiqueta="Persona jurídica" titulo="Cuéntanos sobre tu organización"
    bajada="Primero completa los datos y solicita dos patrocinios. Los documentos se adjuntan después de sus confirmaciones.">
    <IntakeNotice />
    <div className="flex flex-col gap-6 md:flex-row"><Pasos actual={1} />
      <form noValidate className="min-w-0 flex-1 rounded-xl border border-line bg-white p-6" onSubmit={handleSubmit(valores => {
        setJuridica(Object.fromEntries(Object.entries(valores).map(([k, v]) => [k, v.trim()])) as Datos)
        setDatos(null)
        navigate('/postular/patrocinios')
      })}>
        <div className="grid gap-5 sm:grid-cols-2">{campos.map(c => <Field key={c.key} id={c.key} label={c.label} hint={c.hint} error={errors[c.key]?.message}>
          <input id={c.key} type={c.type ?? 'text'} className={inputCls(!!errors[c.key])} aria-invalid={!!errors[c.key]}
            aria-describedby={errors[c.key] ? `${c.key}-hint ${c.key}-error` : `${c.key}-hint`}
            {...register(c.key, { validate: v => v.trim().length > 0 || 'Este dato es obligatorio.', maxLength: { value: c.max, message: `Usa un máximo de ${c.max} caracteres.` },
              ...(c.type === 'email' ? { pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Ingresa un correo válido.' } } : {}) })} />
        </Field>)}</div>
        <div className="mt-5"><Field id="actividad" label="Actividad vinculada a la inteligencia artificial" hint="Describe la actividad de la organización. Compartiremos esta descripción con los socios invitados." error={errors.actividad?.message}>
          <textarea id="actividad" rows={5} className={inputCls(!!errors.actividad)} aria-invalid={!!errors.actividad}
            {...register('actividad', { validate: v => v.trim().length > 0 || 'Describe la actividad.', maxLength: { value: 10000, message: 'Usa un máximo de 10,000 caracteres.' } })} />
        </Field></div>
        <div className="mt-6 flex flex-wrap justify-end gap-3"><button type="button" className={botonSecundario} onClick={() => navigate('/postular')}>Volver</button><button className={botonPrimario}>Solicitar patrocinios →</button></div>
      </form>
    </div>
  </Pagina>
}
