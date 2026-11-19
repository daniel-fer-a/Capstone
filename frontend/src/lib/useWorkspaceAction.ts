import { useRef, useState } from 'react'
import { ApiError } from './api'
import { workspaceRequest } from './workspace'
import { nuevaClave } from './clave'

export function useWorkspaceAction() {
  const pending = useRef<{ url: string; body: unknown; key: string } | null>(null), flight = useRef(false)
  const [busy, setBusy] = useState(false), [uncertain, setUncertain] = useState(false), [error, setError] = useState(''), [message, setMessage] = useState('')
  const [pendingUrl, setPendingUrl] = useState<string | null>(null)
  async function run(url: string, body: unknown, verify: (v: unknown) => unknown, success: (v: unknown) => Promise<void> | void): Promise<boolean> {
    if (flight.current) return false
    if (pending.current && pending.current.url !== url) { setError('Primero recupera la operación pendiente.'); return false }
    const operation = pending.current ?? { url, body, key: nuevaClave() }; pending.current = operation; setPendingUrl(operation.url); flight.current = true; setBusy(true); setError(''); setMessage('')
    let confirmed = false
    try {
      const response = await workspaceRequest(operation.url, operation.body, operation.key); verify(response)
      confirmed = true; pending.current = null; setPendingUrl(null); setUncertain(false); setMessage('Operación confirmada.'); await success(response); return true
    } catch (e) {
      if (confirmed) { setError('La operación quedó confirmada. Recarga la consulta para actualizar la vista.'); setUncertain(false); return true }
      setError(e instanceof Error ? e.message : 'No pudimos confirmar la operación.')
      if (e instanceof ApiError && e.incierto) setUncertain(true)
      else { pending.current = null; setPendingUrl(null); setUncertain(false) }
      return false
    } finally { flight.current = false; setBusy(false) }
  }
  return { run, busy, uncertain, locked: busy || uncertain, pendingUrl, error, message }
}
