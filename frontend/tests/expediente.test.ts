import test from 'node:test'
import assert from 'node:assert/strict'
import { leerGestion, tituloGestion, type Gestion } from '../src/lib/expediente.ts'

const base: Gestion = { effective_outcome: null, appeal: null, corrections: [], membership: null, formalization_enabled: false }
test('una apelación pendiente no se presenta como una aprobación o un rechazo definitivo', () => {
  const g: Gestion = { ...base, appeal: { id: 'ficticio', reason: 'Revisar ingreso', requested_at: '2026-11-01', status: 'pending_assembly', resolution: null } }
  assert.equal(tituloGestion(g,'rejected'),'Apelación pendiente de Asamblea')
})
test('la aprobación de Asamblea y el registro de ingreso son hitos diferentes', () => {
  const g: Gestion = { ...base, effective_outcome: 'approved', appeal: { id: 'ficticio', reason: 'Revisar', requested_at: '2026-11-01', status: 'resolved', resolution: { outcome: 'approved', reason: 'Acuerdo', meeting_date: '2026-11-01', meeting_type: 'ordinary' } } }
  assert.equal(tituloGestion(g,'rejected'),'Aprobada por la Asamblea')
  assert.equal(tituloGestion({ ...g, membership: { effective_date: '2026-11-01', registered_at: '2026-11-01' } },'rejected'),'Ingreso registrado')
})
test('un segundo rechazo identifica a la Asamblea sin simular una solicitud nueva', () => {
  const g: Gestion = { ...base, effective_outcome: 'rejected', appeal: { id: 'ficticio', reason: 'Revisar', requested_at: '2026-11-01', status: 'resolved', resolution: { outcome: 'rejected', reason: 'Acuerdo', meeting_date: '2026-11-01', meeting_type: 'ordinary' } } }
  assert.equal(tituloGestion(g,'rejected'),'Rechazo confirmado por la Asamblea')
})
test('una respuesta vacía o inválida no confirma una actuación', () => {
  for (const value of [null, {}, { data: {} }, { data: { ...base, effective_outcome: 'inventado' } }, { data: { ...base, appeal: {} } }, { data: { ...base, corrections: [{}] } }, { data: { ...base, membership: { effective_date: 'inválida' } } }]) assert.throws(() => leerGestion(value))
  assert.deepEqual(leerGestion({ data: base }),base)
})
