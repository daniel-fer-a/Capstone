import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useForm } from 'react-hook-form'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, inputCls } from '../components/estilos'
import { ApiError } from '../lib/api'
import { peticionPrivada } from '../lib/httpPrivado'

export default function Restablecer() {
  const [enlace] = useState(() => new URLSearchParams(window.location.hash.slice(1)))
  const [listo, setListo] = useState(false)
  const [error, setError] = useState('')
  const { register, handleSubmit, getValues, formState: { isSubmitting, errors } } = useForm<{ password: string; password_confirmation: string }>()
  const token = enlace.get('token') ?? ''
  const email = enlace.get('email') ?? ''
  useEffect(() => { window.history.replaceState(window.history.state, '', '/restablecer') }, [])
  const guardar = handleSubmit(async (datos) => {
    setError('')
    try {
      await peticionPrivada('/api/v1/session/reset-password', { ...datos, token, email })
      setListo(true)
    } catch (err) { setError(err instanceof ApiError && err.status === 422 ? 'El enlace no es válido o ya expiró. Revisa la contraseña o solicita un enlace nuevo.' : err instanceof ApiError ? err.message : 'No pudimos actualizar la contraseña.') }
  })
  return <Pagina migas="Ingresar / Nueva contraseña" etiqueta="Recuperación de acceso" titulo="Establece tu nueva contraseña">
    <section className="max-w-lg rounded-xl border border-line bg-white p-5 sm:p-6">
      {listo ? <div role="status"><Aviso>Contraseña actualizada. Ya puedes iniciar sesión.</Aviso><Link to="/ingresar" className={`${botonPrimario} mt-5`}>Ir a ingresar</Link></div>
        : !token || !email ? <><Aviso tipo="error">Abre el enlace completo que recibiste para restablecer tu contraseña.</Aviso><Link to="/recuperar" className="mt-4 inline-block text-navy underline">Solicitar otro enlace</Link></>
        : <form onSubmit={guardar} noValidate className="space-y-5">
          <Field id="password" label="Nueva contraseña" hint="Al menos 12 caracteres, con letras y números." error={errors.password?.message}><input id="password" type="password" autoComplete="new-password" className={inputCls(!!errors.password)} aria-invalid={!!errors.password} aria-describedby={`password-${errors.password ? 'error' : 'hint'}`} {...register('password', { validate: (v) => new TextEncoder().encode(v).length <= 72 && v.length >= 12 && /[\p{L}]/u.test(v) && /\d/.test(v) || 'Usa al menos 12 caracteres, con letras y números.' })} /></Field>
          <Field id="password_confirmation" label="Repite la contraseña" error={errors.password_confirmation?.message}><input id="password_confirmation" type="password" autoComplete="new-password" className={inputCls(!!errors.password_confirmation)} aria-invalid={!!errors.password_confirmation} aria-describedby={errors.password_confirmation ? 'password_confirmation-error' : undefined} {...register('password_confirmation', { validate: (v) => v === getValues('password') || 'Las contraseñas deben coincidir.' })} /></Field>
          {error && <Aviso tipo="error">{error}</Aviso>}
          <button disabled={isSubmitting} type="submit" className={botonPrimario}>{isSubmitting ? 'Guardando…' : 'Guardar contraseña'}</button>
          <p><Link to="/recuperar" className="text-sm text-navy underline">Solicitar otro enlace</Link></p>
        </form>}
    </section>
  </Pagina>
}
