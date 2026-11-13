import { useEffect, useState } from 'react'
import { capacidadIngreso, type CapacidadIngreso } from '../lib/api'
import { Aviso } from './ui'

export default function IntakeNotice() {
  const [capacidad, setCapacidad] = useState<CapacidadIngreso | null>(null)
  const [error, setError] = useState(false)
  useEffect(() => {
    let activo = true
    capacidadIngreso().then((valor) => { if (activo) setCapacidad(valor) }).catch(() => { if (activo) setError(true) })
    return () => { activo = false }
  }, [])
  if (error) return <div className="mb-5"><Aviso tipo="warn">No pudimos comprobar la disponibilidad del envío. Puedes revisar el formulario; la disponibilidad se verificará antes de enviarlo.</Aviso></div>
  if (!capacidad) return null
  return <div className="mb-5"><Aviso tipo="warn">{capacidad.local_pilot
    ? 'Estás en un entorno de pruebas. Usa únicamente antecedentes ficticios; estos registros no constituyen una postulación institucional completa.'
    : capacidad.sponsor_channel_available
      ? 'Primero solicita dos patrocinios. Cuando ambos socios los confirmen desde sus cuentas, podrás continuar con los documentos y el envío.'
      : 'La solicitud de patrocinios no está disponible en este momento. Puedes retomar un registro existente desde la opción Retomar.'}</Aviso></div>
}
