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
    : 'Puedes revisar el formulario. El envío requiere dos socios patrocinadores y se habilitará cuando esté disponible el canal privado para acreditarlos.'}</Aviso></div>
}
