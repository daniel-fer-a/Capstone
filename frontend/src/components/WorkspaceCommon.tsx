import type { ReactNode } from 'react'
import { Aviso } from './ui'
import { botonPrimario, botonSecundario } from './estilos'
import type { useWorkspaceAction } from '../lib/useWorkspaceAction'

export type WorkspaceAction = ReturnType<typeof useWorkspaceAction>
export function ResultadoAccion({ action }: { action: WorkspaceAction }) {
  return <div className="my-4 space-y-3" aria-live="polite">{action.error && <Aviso tipo="error">{action.error}</Aviso>}{action.message && <Aviso>{action.message}</Aviso>}{action.uncertain && <Aviso tipo="warn">Conservamos los datos de este envío. Recupera la misma operación antes de cambiar de pantalla.</Aviso>}</div>
}
export function GuardarWorkspace({ action, url, children = 'Guardar' }: { action: WorkspaceAction; url: string; children?: ReactNode }) {
  return <button className={`${botonPrimario} mt-4`} disabled={action.busy || (action.uncertain && action.pendingUrl !== url)}>{action.busy ? 'Confirmando…' : action.uncertain ? 'Recuperar la misma operación' : children}</button>
}
export function PaginacionWorkspace({ page, last, change, disabled = false }: { page: number; last: number; change: (p: number) => void; disabled?: boolean }) {
  return <div className="my-4 flex flex-wrap items-center justify-between gap-2 text-sm"><button type="button" className={botonSecundario} disabled={disabled || page <= 1} onClick={() => change(page - 1)}>Anterior</button><span>Página {page} de {last}</span><button type="button" className={botonSecundario} disabled={disabled || page >= last} onClick={() => change(page + 1)}>Siguiente</button></div>
}
export const panelWorkspace = 'my-6 min-w-0 space-y-4 rounded-xl border border-line bg-white p-5'
