export const ESTADOS: Record<string, string> = {
  received: 'Recibida', under_review: 'En revisión', approved: 'Aprobada', rejected: 'Rechazada',
}
export function nombreEstado(estado: string): string { return ESTADOS[estado] ?? 'Estado no disponible' }
