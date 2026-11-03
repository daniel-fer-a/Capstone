import { useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { ApiError } from '../lib/api'
import { peticionPrivada } from '../lib/httpPrivado'
import { botonPrimario, inputCls } from './estilos'
import { Aviso, Field } from './ui'

type Acuerdo = { outcome: string; meeting_date: string; minutes_reference: string; reason: string }

export default function RegistrarResolucion({ id, alRegistrar }: { id: string; alRegistrar: () => Promise<void> }) {
  const [ocupado, setOcupado] = useState(false)
  const [error, setError] = useState('')
  const [confirmado, setConfirmado] = useState(false)
  const bloqueo = useRef(false)
  const { register, handleSubmit, formState: { errors } } = useForm<Acuerdo>()
  const registrar = async (data: Acuerdo) => {
    if (bloqueo.current || !confirmado) return
    bloqueo.current = true; setOcupado(true); setError('')
    try { await peticionPrivada(`/api/v1/admin/applications/${id}/decision`, data); await alRegistrar() }
    catch (e) { setError(e instanceof ApiError ? e.status === 422 ? 'Revisa el acuerdo, su fecha, fundamento y referencia de acta.' : e.message : 'No pudimos confirmar el registro. Consulta el expediente antes de cambiar el acuerdo.') }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  return <form className="mt-6 space-y-4 border-t border-line pt-5" noValidate onSubmit={ev => { void handleSubmit(registrar)(ev) }}>
    <h3 className="text-xl font-bold text-navy">Registrar resolución del Directorio</h3>
    <Aviso>Registra un acuerdo ya adoptado por el Directorio. El registro conserva el responsable y no asigna membresía automáticamente.</Aviso>
    <fieldset disabled={ocupado} className="space-y-4">
      <Field id="outcome" label="Resultado del acuerdo" error={errors.outcome?.message}><select id="outcome" className={inputCls(!!errors.outcome)} {...register('outcome', { required: 'Selecciona el resultado.' })}><option value="">Selecciona</option><option value="approved">Aprobar solicitud</option><option value="rejected">Rechazar solicitud</option></select></Field>
      <Field id="meeting_date" label="Fecha de la sesión" error={errors.meeting_date?.message}><input id="meeting_date" type="date" className={inputCls(!!errors.meeting_date)} {...register('meeting_date', { required: 'Indica la fecha de la sesión.' })} /></Field>
      <Field id="minutes_reference" label="Referencia de acta o sesión" error={errors.minutes_reference?.message}><input id="minutes_reference" className={inputCls(!!errors.minutes_reference)} {...register('minutes_reference', { validate: v => v.trim().length > 0 || 'Indica la referencia del acuerdo.', maxLength: { value: 255, message: 'Usa hasta 255 caracteres.' } })} /></Field>
      <Field id="reason" label="Fundamento de la resolución" hint="Este fundamento podrá consultarlo quien postuló mediante su acceso privado." error={errors.reason?.message}><textarea id="reason" rows={4} className={inputCls(!!errors.reason)} {...register('reason', { validate: v => v.trim().length > 0 || 'Indica el fundamento.', maxLength: { value: 10000, message: 'Usa hasta 10,000 caracteres.' } })} /></Field>
      <label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 h-4 w-4 shrink-0" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} />Confirmo que estos antecedentes corresponden a la resolución adoptada por el Directorio. El acuerdo inicial quedará conservado sin sobrescribirse.</label>
    </fieldset>
    {error && <Aviso tipo="error">{error}</Aviso>}<button className={botonPrimario} disabled={ocupado || !confirmado}>{ocupado ? 'Registrando…' : 'Registrar resolución'}</button>
  </form>
}
