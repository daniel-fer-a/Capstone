import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { ApiError } from './api'
import { leerSesion, peticionPrivada, type Sesion } from './httpPrivado'

export function useSesionInstitucional() {
  const navigate = useNavigate()
  const [sesion, setSesion] = useState<Sesion | null>(null), [error, setError] = useState('')
  useEffect(() => {
    let activo = true
    peticionPrivada('/api/v1/session/me').then(c => { if (activo) setSesion(leerSesion(c)) })
      .catch((e: unknown) => { if (!activo) return; if (e instanceof ApiError && e.status === 401) navigate('/ingresar', { replace: true }); else setError('No pudimos comprobar la sesión. Vuelve a ingresar.') })
    return () => { activo = false }
  }, [navigate])
  return { sesion, error }
}
