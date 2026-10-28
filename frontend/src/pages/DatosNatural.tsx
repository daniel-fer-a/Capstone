import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useLocation, useNavigate } from 'react-router'
import Pasos from '../components/Pasos'
import { botonPrimario, botonSecundario, describedBy, Field, inputCls, Pagina } from '../components/ui'
import type { CampoFormulario } from '../lib/api'
import { usePostulacion, type DatosNatural as Datos } from '../postulacion/postulacionContext'

const CAMPOS_PASO_1: CampoFormulario[] = ['nombres', 'apellidos', 'identificacion', 'correo', 'domicilio', 'actividad']

export default function DatosNatural() {
  const navigate = useNavigate()
  const location = useLocation()
  const { datos, setDatos } = usePostulacion()
  const erroresServidor = (location.state as { errores?: Partial<Record<CampoFormulario, string>> } | null)?.errores

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<Datos>({
    defaultValues: datos ?? {
      nombres: '',
      apellidos: '',
      identificacion: '',
      correo: '',
      domicilio: '',
      actividad: '',
    },
  })

  useEffect(() => {
    if (!erroresServidor) return
    for (const campo of CAMPOS_PASO_1) {
      const mensaje = erroresServidor[campo]
      if (mensaje) setError(campo as keyof Datos, { type: 'server', message: mensaje })
    }
  }, [erroresServidor, setError])

  const continuar = handleSubmit((valores) => {
    setDatos(valores)
    navigate('/postular/natural/antecedentes')
  })

  const e = (campo: keyof Datos) => errors[campo]?.message

  return (
    <Pagina
      migas="Postulación / Socio"
      etiqueta="Persona natural"
      titulo="Cuéntanos sobre ti"
      bajada="Completa los datos para identificar la solicitud y la actividad vinculada con la inteligencia artificial."
    >
      <div className="flex flex-col gap-6 md:flex-row">
        <Pasos actual={1} />
        <form onSubmit={continuar} noValidate className="flex-1 rounded-xl border border-line bg-white p-6">
          <h2 className="text-xl font-bold text-navy">Tus antecedentes personales</h2>
          <p className="mb-5 text-sm text-muted">Todos los campos de este paso son obligatorios.</p>

          <div className="grid gap-5 sm:grid-cols-2">
            <Field id="nombres" label="Nombres" hint="Escribe tus nombres." error={e('nombres')}>
              <input
                id="nombres"
                autoComplete="given-name"
                className={inputCls(!!e('nombres'))}
                aria-invalid={!!e('nombres')}
                aria-describedby={describedBy('nombres', e('nombres'))}
                {...register('nombres', {
                  validate: (v) => v.trim().length > 0 || 'Ingresa tus nombres.',
                  maxLength: { value: 120, message: 'Usa un máximo de 120 caracteres.' },
                })}
              />
            </Field>
            <Field id="apellidos" label="Apellidos" hint="Escribe tus apellidos." error={e('apellidos')}>
              <input
                id="apellidos"
                autoComplete="family-name"
                className={inputCls(!!e('apellidos'))}
                aria-invalid={!!e('apellidos')}
                aria-describedby={describedBy('apellidos', e('apellidos'))}
                {...register('apellidos', {
                  validate: (v) => v.trim().length > 0 || 'Ingresa tus apellidos.',
                  maxLength: { value: 120, message: 'Usa un máximo de 120 caracteres.' },
                })}
              />
            </Field>
          </div>

          <div className="mt-5">
            <Field
              id="identificacion"
              label="Cédula de identidad / identificación"
              hint="Cédula de identidad u otra identificación aplicable."
              error={e('identificacion')}
            >
              <input
                id="identificacion"
                className={inputCls(!!e('identificacion'))}
                aria-invalid={!!e('identificacion')}
                aria-describedby={describedBy('identificacion', e('identificacion'))}
                {...register('identificacion', {
                  validate: (v) => v.trim().length > 0 || 'Ingresa tu identificación.',
                  maxLength: { value: 80, message: 'Usa un máximo de 80 caracteres.' },
                })}
              />
            </Field>
          </div>

          <div className="mt-5">
            <Field id="correo" label="Correo electrónico" hint="Usa un correo al que tengas acceso." error={e('correo')}>
              <input
                id="correo"
                type="email"
                autoComplete="email"
                className={inputCls(!!e('correo'))}
                aria-invalid={!!e('correo')}
                aria-describedby={describedBy('correo', e('correo'))}
                {...register('correo', {
                  required: 'Ingresa tu correo electrónico.',
                  pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Ingresa un correo electrónico válido.' },
                  maxLength: { value: 255, message: 'Usa un máximo de 255 caracteres.' },
                })}
              />
            </Field>
          </div>

          <div className="mt-5">
            <Field
              id="domicilio"
              label="Región y comuna"
              hint="Ejemplo: IX Región, Temuco."
              error={e('domicilio')}
            >
              <input
                id="domicilio"
                autoComplete="off"
                className={inputCls(!!e('domicilio'))}
                aria-invalid={!!e('domicilio')}
                aria-describedby={describedBy('domicilio', e('domicilio'))}
                {...register('domicilio', {
                  validate: (v) => v.trim().length > 0 || 'Indica tu región y comuna.',
                  maxLength: { value: 500, message: 'Usa un máximo de 500 caracteres.' },
                })}
              />
            </Field>
          </div>

          <div className="mt-5">
            <Field
              id="actividad"
              label="Actividad vinculada a la inteligencia artificial"
              hint="Describe la actividad de inteligencia artificial que realizas."
              error={e('actividad')}
            >
              <textarea
                id="actividad"
                rows={5}
                className={inputCls(!!e('actividad'))}
                aria-invalid={!!e('actividad')}
                aria-describedby={describedBy('actividad', e('actividad'))}
                {...register('actividad', {
                  validate: (v) => v.trim().length > 0 || 'Describe tu actividad.',
                  maxLength: { value: 10000, message: 'Usa un máximo de 10.000 caracteres.' },
                })}
              />
            </Field>
          </div>

          <div className="mt-6 flex justify-end gap-3">
            <button type="button" className={botonSecundario} onClick={() => navigate('/postular')}>
              Volver
            </button>
            <button type="submit" className={botonPrimario}>Continuar →</button>
          </div>
        </form>
      </div>
    </Pagina>
  )
}
