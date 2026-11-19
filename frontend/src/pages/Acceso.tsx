import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useForm } from 'react-hook-form'
import { Aviso, Field, Pagina } from '../components/ui'
import { ApiError } from '../lib/api'
import { peticionPrivada } from '../lib/httpPrivado'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { MODO_DEMO, SOCIO_1, SOCIO_2 } from '../lib/demo'

export default function Acceso() {
  const navigate = useNavigate()
  const [error, setError] = useState('')
  const { register, handleSubmit, setValue, formState: { isSubmitting, errors } } = useForm<{ email: string; password: string }>()
  const ingresar = handleSubmit(async (datos) => {
    setError('')
    try {
      await peticionPrivada('/api/v1/session/login', { email: datos.email.trim(), password: datos.password })
      navigate('/area', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError && err.status === 422 ? 'El correo o la contraseña no son correctos.' : err instanceof ApiError ? err.message : 'No pudimos iniciar sesión. Inténtalo nuevamente.')
    }
  })
  return <Pagina migas="Ingresar" etiqueta="Área privada" titulo="Ingresa a tu cuenta" bajada="Accede con una cuenta habilitada por la CCHIA.">
    <form onSubmit={ingresar} className="max-w-lg space-y-5 rounded-xl border border-line bg-white p-5 sm:p-6" noValidate>
      {MODO_DEMO && (
        <div className="rounded-lg bg-tint p-3">
          <p className="mb-2 text-sm font-semibold text-navy">Modo demostración: rellenar credenciales</p>
          <div className="flex flex-wrap gap-2">
            <button type="button" className={botonSecundario}
              onClick={() => { setValue('email', SOCIO_1.email); setValue('password', SOCIO_1.password) }}>
              Socio 1
            </button>
            <button type="button" className={botonSecundario}
              onClick={() => { setValue('email', SOCIO_2.email); setValue('password', SOCIO_2.password) }}>
              Socio 2
            </button>
          </div>
        </div>
      )}
      <Field id="email" label="Correo electrónico" error={errors.email?.message}><input id="email" type="email" autoComplete="username" aria-invalid={!!errors.email} aria-describedby={errors.email ? 'email-error' : undefined} className={inputCls(!!errors.email)} {...register('email', { required: 'Ingresa tu correo.', pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Ingresa un correo válido.' }, maxLength: { value: 255, message: 'Usa un máximo de 255 caracteres.' } })} /></Field>
      <Field id="password" label="Contraseña" error={errors.password?.message}><input id="password" type="password" autoComplete="current-password" aria-invalid={!!errors.password} aria-describedby={errors.password ? 'password-error' : undefined} className={inputCls(!!errors.password)} {...register('password', { required: 'Ingresa tu contraseña.', maxLength: { value: 1024, message: 'La contraseña es demasiado larga.' } })} /></Field>
      {error && <Aviso tipo="error">{error}</Aviso>}
      <button type="submit" disabled={isSubmitting} className={botonPrimario}>{isSubmitting ? 'Ingresando…' : 'Ingresar'}</button>
      <p><Link to="/recuperar" className="text-sm text-navy underline">Olvidé mi contraseña</Link></p>
      <p className="text-sm text-muted">La postulación es pública y se realiza desde <Link to="/postular" className="text-navy underline">Postular como socio</Link>.</p>
    </form>
  </Pagina>
}
