import { useMemo, useState, type ReactNode } from 'react'
import { PostulacionContext, type Comprobante, type DatosNatural } from './postulacionContext'

export function PostulacionProvider({ children }: { children: ReactNode }) {
  const [datos, setDatos] = useState<DatosNatural | null>(null)
  const [respaldo, setRespaldo] = useState<File | null>(null)
  const [comprobante, setComprobante] = useState<Comprobante | null>(null)
  const [claveEnvio, setClaveEnvio] = useState(() => crypto.randomUUID())

  const valor = useMemo(
    () => ({
      datos,
      setDatos,
      respaldo,
      setRespaldo,
      comprobante,
      setComprobante,
      claveEnvio,
      reiniciar: () => {
        setDatos(null)
        setRespaldo(null)
        setComprobante(null)
        setClaveEnvio(crypto.randomUUID())
      },
    }),
    [datos, respaldo, comprobante, claveEnvio],
  )

  return <PostulacionContext.Provider value={valor}>{children}</PostulacionContext.Provider>
}
