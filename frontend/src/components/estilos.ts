export const inputCls = (error: boolean) =>
  `w-full rounded-md border bg-white px-3.5 py-3 text-ink placeholder:text-muted/70 ${
    error ? 'border-danger' : 'border-line hover:border-muted/60'
  }`

export const botonPrimario =
  'inline-flex items-center justify-center gap-2 rounded-md bg-navy px-5 py-3 text-sm font-bold text-white hover:bg-navy/90 disabled:cursor-not-allowed disabled:opacity-55'
export const botonSecundario =
  'inline-flex items-center justify-center gap-2 rounded-md border border-line bg-white px-5 py-3 text-sm font-bold text-navy hover:bg-tint'


export function describedBy(id: string, error?: string) {
  return `${id}-${error ? 'error' : 'hint'}`
}

