import { useMemo, useState, type ReactNode } from 'react'
import { PostulacionContext, type Comprobante, type DatosNatural, type DatosJuridica, type SolicitudPrevia } from './postulacionContext'

export function PostulacionProvider({ children }: { children: ReactNode }) {
  const [datos, setDatos] = useState<DatosNatural | null>(null)
  const [respaldo, setRespaldo] = useState<File | null>(null)
  const [comprobante, setComprobante] = useState<Comprobante | null>(null)
  const [claveEnvio, setClaveEnvio] = useState(() => crypto.randomUUID())
  const [juridica, setJuridica] = useState<DatosJuridica | null>(null)
  const [previa, setPrevia] = useState<SolicitudPrevia | null>(null)
  const [estatutos, setEstatutos] = useState<File | null>(null)
  const [vigencia, setVigencia] = useState<File | null>(null)

  const valor = useMemo(
    () => ({
      datos,
      setDatos,
      respaldo,
      setRespaldo,
      comprobante,
      setComprobante,
      claveEnvio,
      juridica, setJuridica, previa, setPrevia, estatutos, setEstatutos, vigencia, setVigencia,
      reiniciar: () => {
        setDatos(null)
        setRespaldo(null)
        setComprobante(null)
        setClaveEnvio(crypto.randomUUID())
        setJuridica(null)
        setPrevia(null)
        setEstatutos(null)
        setVigencia(null)
      },
    }),
    [datos, respaldo, comprobante, claveEnvio, juridica, previa, estatutos, vigencia],
  )

  return <PostulacionContext.Provider value={valor}>{children}</PostulacionContext.Provider>
}
