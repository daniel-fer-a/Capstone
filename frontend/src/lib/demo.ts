import type { DatosJuridica, DatosNatural } from '../postulacion/postulacionContext'

// Ponlo en false para ocultar el botón de relleno.
export const MODO_DEMO = true
const sufijo = () => Date.now().toString(36)

export const ejemploNatural = (): DatosNatural => ({
  nombres: 'Camila Andrea', apellidos: 'Rojas Soto', edad: '34', identificacion: '12.345.678-5',
  correo: `demo.${sufijo()}@example.test`, region: 'Metropolitana de Santiago', comuna: 'Vitacura',
  actividad: 'Desarrollo de asistentes conversacionales con IA para atención de clientes en pymes (datos de ejemplo).',
})

export const ejemploJuridica = (): DatosJuridica => ({
  razonSocial: 'Demo Inteligencia Artificial SpA', rutOrganizacion: '76.123.456-7',
  representante: 'Camila Andrea Rojas Soto', rutRepresentante: '12.345.678-5',
  correo: `demo.${sufijo()}@example.test`, region: 'Metropolitana de Santiago', comuna: 'Vitacura',
  actividad: 'Plataforma de análisis de documentos con IA para organizaciones chilenas (datos de ejemplo).',
})