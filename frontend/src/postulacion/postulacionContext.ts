import { createContext, useContext } from 'react'

export type DatosNatural = {
  nombres: string
  apellidos: string
  edad: string
  identificacion: string
  correo: string
  region: string
  comuna: string
  actividad: string
}

export type Comprobante = {
  id: string
  folio: string
  estado: string
  recibidaEn: string
  codigoSeguimiento: string
  prueba: boolean
  solicitudPreviaId?: string
}

export type DatosJuridica = {
  razonSocial: string
  rutOrganizacion: string
  representante: string
  rutRepresentante: string
  correo: string
  region: string
  comuna: string
  actividad: string
}

export type SolicitudPrevia = {
  id: string
  reference: string
  codigo: string
  legal: 'natural' | 'juridica'
  created_at: string
  stage: 'waiting_sponsors' | 'ready_for_documents' | 'submitted'
  ready_for_documents: boolean
  payload: Record<string, string | number | boolean | null>
  invitations: { position: number; email: string; state: 'pending' | 'accepted' | 'declined' | 'confirmation_unavailable'; decided_at: string | null }[]
  application: { reference: string; status: string; submitted_at: string } | null
}

export type PostulacionCtx = {
  datos: DatosNatural | null
  setDatos: (d: DatosNatural | null) => void
  respaldo: File | null
  setRespaldo: (f: File | null) => void
  comprobante: Comprobante | null
  setComprobante: (c: Comprobante) => void
  reiniciar: () => void
  claveEnvio: string
  juridica: DatosJuridica | null
  setJuridica: (d: DatosJuridica | null) => void
  previa: SolicitudPrevia | null
  setPrevia: (p: SolicitudPrevia | null) => void
  estatutos: File | null
  setEstatutos: (f: File | null) => void
  vigencia: File | null
  setVigencia: (f: File | null) => void
}

export const PostulacionContext = createContext<PostulacionCtx | null>(null)

export function usePostulacion(): PostulacionCtx {
  const ctx = useContext(PostulacionContext)
  if (!ctx) throw new Error('usePostulacion debe usarse dentro de PostulacionProvider')
  return ctx
}
