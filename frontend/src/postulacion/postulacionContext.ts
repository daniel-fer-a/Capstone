import { createContext, useContext } from 'react'

export type DatosNatural = {
  nombres: string
  apellidos: string
  identificacion: string
  correo: string
  domicilio: string
  actividad: string
}

export type Comprobante = {
  id: string
  folio: string
  estado: string
  recibidaEn: string
  codigoSeguimiento: string
}

export type PostulacionCtx = {
  datos: DatosNatural | null
  setDatos: (d: DatosNatural | null) => void
  respaldo: File | null
  setRespaldo: (f: File | null) => void
  comprobante: Comprobante | null
  setComprobante: (c: Comprobante) => void
  reiniciar: () => void
}

export const PostulacionContext = createContext<PostulacionCtx | null>(null)

export function usePostulacion(): PostulacionCtx {
  const ctx = useContext(PostulacionContext)
  if (!ctx) throw new Error('usePostulacion debe usarse dentro de PostulacionProvider')
  return ctx
}
