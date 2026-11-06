import { useState } from 'react'
import { Link } from 'react-router'
import { useForm } from 'react-hook-form'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, inputCls } from '../components/estilos'
import { ApiError } from '../lib/api'
import { peticionPrivada } from '../lib/httpPrivado'

export default function Recuperar() {
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const { register, handleSubmit, formState: { isSubmitting, errors } } = useForm<{ email: string }>()
  const enviar = handleSubmit(async (datos) => {
    setError(''); setMensaje('')
    try {
      await peticionPrivada('/api/v1/session/forgot-password', { email: datos.email.trim() })
      setMensaje('Si existe una cuenta con ese correo, recibirás instrucciones para recuperar el acceso.')
    } catch (err) { setError(err instanceof ApiError ? err.message : 'No pudimos procesar la solicitud. Inténtalo nuevamente.') }
  })
  return <Pagina migas="Ingresar / Recuperar acceso" etiqueta="Recuperación de acceso" titulo="Recupera tu contraseña" bajada="Escribe el correo asociado a tu cuenta.">
    <form onSubmit={enviar} noValidate className="max-w-lg space-y-5 rounded-xl border border-line bg-white p-5 sm:p-6">
      <Field id="email" label="Correo electrónico" error={errors.email?.message}><input id="email" type="email" autoComplete="email" className={inputCls(!!errors.email)} aria-invalid={!!errors.email} aria-describedby={errors.email ? 'email-error' : undefined} {...register('email', { required: 'Ingresa tu correo.', pattern: { value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/, message: 'Ingresa un correo válido.' }, maxLength: { value: 255, message: 'Usa un máximo de 255 caracteres.' } })} /></Field>
      {mensaje && <div role="status"><Aviso>{mensaje}</Aviso></div>}{error && <Aviso tipo="error">{error}</Aviso>}
      <button disabled={isSubmitting} type="submit" className={botonPrimario}>{isSubmitting ? 'Solicitando…' : 'Solicitar enlace'}</button>
      <p><Link to="/ingresar" className="text-sm text-navy underline">Volver al inicio de sesión</Link></p>
    </form>
  </Pagina>
}
