import { spawn } from 'node:child_process'
import { createHash, randomBytes } from 'node:crypto'
import fs from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root=path.dirname(path.dirname(fileURLToPath(import.meta.url)))
if(process.platform==='win32') throw new Error('Ejecutar desde WSL o Linux con Node 24 y npm.')
if(Number(process.versions.node.split('.')[0])!==24) throw new Error('Esta entrega utiliza Node 24.')
process.umask(0o077)
const manifestPath=path.join(root,'operacion/CODIGO_SHA256.json')
const manifest=JSON.parse(await fs.readFile(manifestPath,'utf8'))
if(!Array.isArray(manifest.files)||manifest.files.length===0||new Set(manifest.files.map(row=>row.path)).size!==manifest.files.length) throw new Error('Manifiesto de código inválido.')
const originalManifestHash=createHash('sha256').update(await fs.readFile(manifestPath)).digest('hex')
async function verifyCode() {
for(const row of manifest.files) {
  if(typeof row.path!=='string'||path.isAbsolute(row.path)||row.path.split('/').includes('..')||row.path.includes('\\')||typeof row.sha256!=='string'||!/^[a-f0-9]{64}$/.test(row.sha256)) throw new Error('Ruta o huella inválida en manifiesto.')
  const actual=createHash('sha256').update(await fs.readFile(path.join(root,row.path))).digest('hex')
  if(actual!==row.sha256) throw new Error('El código cambió desde esta entrega. Registrar la nueva versión antes de emitir evidencia.')
}
if(createHash('sha256').update(await fs.readFile(manifestPath)).digest('hex')!==originalManifestHash) throw new Error('El manifiesto cambió durante la verificación.')
}
await verifyCode()
const stamp=new Date().toISOString().replaceAll(/[-:.]/g,'')+'-'+randomBytes(3).toString('hex')
const out=path.join(root,'operacion/private/evidencias','frontend-'+stamp)
await fs.mkdir(out,{recursive:true,mode:0o700})
const testFiles=(await fs.readdir(path.join(root,'frontend/tests'))).filter(n=>n.endsWith('.test.ts')).sort().map(n=>'tests/'+n)
const commands=[['test',process.execPath,['--test','--test-reporter=junit',...testFiles]],['lint','npm',['run','lint']],['build','npm',['run','build']]]
const results=[]
for(const [name,binary,args] of commands) {
  const result=await new Promise(resolve=>{
    const child=spawn(binary,args,{cwd:path.join(root,'frontend'),env:{...process.env,PATH:path.dirname(process.execPath)+path.delimiter+(process.env.PATH??'')},stdio:['ignore','pipe','pipe']})
    let stdout='',stderr=''
    child.stdout.setEncoding('utf8');child.stderr.setEncoding('utf8')
    child.stdout.on('data',d=>stdout+=d);child.stderr.on('data',d=>stderr+=d)
    child.on('error',()=>resolve({exit_code:1,stdout,stderr:'No fue posible iniciar el proceso.'}))
    child.on('close',code=>resolve({exit_code:code??1,stdout,stderr}))
  })
  const file=name==='test'?'frontend.xml':name+'.log'
  await fs.writeFile(path.join(out,file),result.stdout,{mode:0o600})
  await fs.writeFile(path.join(out,name+'.stderr.log'),result.stderr,{mode:0o600})
  results.push({name,exit_code:result.exit_code,stdout_file:file,stderr_file:name+'.stderr.log',stdout_sha256:createHash('sha256').update(result.stdout).digest('hex')})
}
await verifyCode()
const report={schema_version:1,generated_at:new Date().toISOString(),code_manifest_sha256:originalManifestHash,commands:results,all_passed:results.every(r=>r.exit_code===0)}
await fs.writeFile(path.join(out,'frontend.json'),JSON.stringify(report,null,2)+'\n',{mode:0o600})
process.stdout.write(JSON.stringify({all_passed:report.all_passed,report:path.relative(root,path.join(out,'frontend.json'))})+'\n')
process.exitCode=report.all_passed?0:1
