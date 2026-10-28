import { useMemo, useState, type ReactNode } from 'react'
import { PostulacionContext, type Comprobante, type DatosNatural } from './postulacionContext'

// Todo vive solo en memoria: no se guardan datos personales ni el código de
// seguimiento en localStorage ni en la URL.
export function PostulacionProvider({ children }: { children: ReactNode }) {
  const [datos, setDatos] = useState<DatosNatural | null>(null)
  const [respaldo, setRespaldo] = useState<File | null>(null)
  const [comprobante, setComprobante] = useState<Comprobante | null>(null)

  const valor = useMemo(
    () => ({
      datos,
      setDatos,
      respaldo,
      setRespaldo,
      comprobante,
      setComprobante,
      reiniciar: () => {
        setDatos(null)
        setRespaldo(null)
        setComprobante(null)
      },
    }),
    [datos, respaldo, comprobante],
  )

  return <PostulacionContext.Provider value={valor}>{children}</PostulacionContext.Provider>
}
