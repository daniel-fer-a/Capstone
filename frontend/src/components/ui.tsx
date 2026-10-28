import type { ReactNode } from 'react'
import { Link } from 'react-router'

export const inputCls = (error: boolean) =>
  `w-full rounded-md border bg-white px-3.5 py-3 text-ink placeholder:text-muted/70 ${
    error ? 'border-danger' : 'border-line hover:border-muted/60'
  }`

export const botonPrimario =
  'inline-flex items-center justify-center gap-2 rounded-md bg-navy px-5 py-3 text-sm font-bold text-white hover:bg-navy/90 disabled:cursor-not-allowed disabled:opacity-55'
export const botonSecundario =
  'inline-flex items-center justify-center gap-2 rounded-md border border-line bg-white px-5 py-3 text-sm font-bold text-navy hover:bg-tint'

export function Pagina({
  migas,
  etiqueta,
  titulo,
  bajada,
  children,
}: {
  migas: string
  etiqueta: string
  titulo: string
  bajada?: string
  children: ReactNode
}) {
  return (
    <div className="mx-auto max-w-5xl px-5 py-8">
      <nav aria-label="Ruta" className="mb-6 text-xs text-muted">
        <Link to="/" className="hover:underline">Inicio</Link> / {migas}
      </nav>
      <p className="text-[11px] font-extrabold uppercase tracking-[0.14em] text-navy">{etiqueta}</p>
      <h1 className="mt-1 text-4xl font-extrabold text-navy">{titulo}</h1>
      {bajada && <p className="mt-3 max-w-2xl text-lg text-muted">{bajada}</p>}
      <div className="mt-8">{children}</div>
    </div>
  )
}

export function Field({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string
  label: string
  hint?: string
  error?: string
  children: ReactNode
}) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-bold text-ink">{label}</label>
      {children}
      {error ? (
        <p id={`${id}-error`} role="alert" className="mt-1.5 text-xs font-semibold text-danger">{error}</p>
      ) : (
        hint && <p id={`${id}-hint`} className="mt-1.5 text-xs text-muted">{hint}</p>
      )}
    </div>
  )
}

export function describedBy(id: string, error?: string) {
  return `${id}-${error ? 'error' : 'hint'}`
}

export function Aviso({
  tipo = 'info',
  children,
}: {
  tipo?: 'info' | 'error' | 'warn'
  children: ReactNode
}) {
  const estilos = {
    info: 'border-line bg-tint text-ink',
    error: 'border-danger/30 bg-danger-bg text-danger',
    warn: 'border-warn/30 bg-warn-bg text-warn',
  }[tipo]
  return (
    <div role={tipo === 'error' ? 'alert' : undefined} className={`rounded-md border px-4 py-3 text-sm ${estilos}`}>
      {children}
    </div>
  )
}
