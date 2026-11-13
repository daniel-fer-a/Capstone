import { useEffect } from 'react'
import { useLocation } from 'react-router'

export default function RouteFocus() {
  const { pathname } = useLocation()
  useEffect(() => {
    document.title = 'CCHIA · Ingreso de socios'
    document.getElementById('contenido')?.focus({ preventScroll: true })
    window.scrollTo(0, 0)
  }, [pathname])
  return null
}
