import test from 'node:test'
import assert from 'node:assert/strict'
import { esAvisoCorreo, esIngreso, leerDetalleIngreso, leerPagina, nombreEstadoAviso, type AvisoCorreo } from '../src/lib/operacion.ts'
const id='11111111-1111-4111-8111-111111111111'
const aviso: AvisoCorreo={id,kind:'application_event',attempts:5,created_at:'2026-11-02',available_at:'2026-11-02',sent_at:null,abandoned_at:'2026-11-02',failed:true,can_retry:true}
test('una lista institucional dañada no se interpreta como un registro válido',()=>{
  for(const c of [null,{}, {data:[{}],last_page:1},{data:[],last_page:0},{data:[],last_page:1.5}]) assert.throws(()=>leerPagina(c,esIngreso))
  assert.deepEqual(leerPagina({data:[],last_page:1},esIngreso),{rows:[],last:1})
})
test('la ficha exige identificación de la modalidad y datos declarados completos',()=>{
  const ingreso={id,register_reference:'QA-001',legal:'juridica',effective_date:'2026-11-02',recorded_at:'2026-11-02',natural_name:null,organization_name:'Ficticia'}
  assert.ok(esIngreso(ingreso));assert.throws(()=>leerDetalleIngreso({data:ingreso}))
  const detalle={...ingreso,application_reference:'CCHIA-FICTICIO',natural_identifier:null,organization_identifier:'FICTICIO',representative_name:'Ficticia',representative_identifier:'FICTICIO',email:'ficticia@example.test',address:'Región, comuna',region:'Región',commune:'Comuna',ai_activity:'IA ficticia'}
  assert.equal(leerDetalleIngreso({data:detalle}).organization_name,'Ficticia')
})
test('un aviso malformado no habilita reintentos en la interfaz',()=>{
  assert.ok(esAvisoCorreo(aviso))
  for(const c of [{...aviso,id:'invalido'},{...aviso,attempts:6},{...aviso,can_retry:'true'},{...aviso,kind:'inventado'}]) assert.equal(esAvisoCorreo(c),false)
})
test('el texto distingue un aviso procesado de recepción o lectura confirmadas',()=>{
  assert.equal(nombreEstadoAviso({...aviso,sent_at:'2026-11-02'}),'Procesado por el transporte')
  assert.equal(nombreEstadoAviso(aviso),'Intentos agotados por error')
  assert.equal(nombreEstadoAviso({...aviso,failed:false}),'Omitido por vigencia o estado')
  assert.equal(nombreEstadoAviso({...aviso,abandoned_at:null}),'Pendiente o en reintento')
})
