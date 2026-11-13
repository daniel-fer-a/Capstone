export function nuevaClave(): string {
    const c = globalThis.crypto
    if (typeof c?.randomUUID === 'function') return c.randomUUID()
    const bytes = c.getRandomValues(new Uint8Array(16))
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  }