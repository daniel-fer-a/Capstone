import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { Aviso, Field, Pagina } from '../components/ui'
import { botonPrimario, botonSecundario, inputCls } from '../components/estilos'
import { GuardarWorkspace, panelWorkspace, ResultadoAccion } from '../components/WorkspaceCommon'
import { ApiError } from '../lib/api'
import { useSesionInstitucional } from '../lib/useSesionInstitucional'
import { useWorkspaceAction } from '../lib/useWorkspaceAction'
import { datos, leerPerfil, workspaceRequest, type Perfil } from '../lib/workspace'

export default function MiPerfil() {
  const { sesion, error: sessionError } = useSesionInstitucional(), action = useWorkspaceAction(), navigate = useNavigate()
  const [profile, setProfile] = useState<Perfil | null>(null), [loadError, setLoadError] = useState('')
  const [current, setCurrent] = useState(''), [password, setPassword] = useState(''), [confirmation, setConfirmation] = useState(''), [passwordError, setPasswordError] = useState(''), [passwordBusy, setPasswordBusy] = useState(false), [passwordUncertain, setPasswordUncertain] = useState(false)
  const passwordFlight = useRef(false)
  useEffect(() => {
    let active = true
    workspaceRequest('/api/v1/profile').then(c => { const p = leerPerfil(c); if (active) setProfile(p) }).catch((e: unknown) => { if (active) setLoadError(e instanceof Error ? e.message : 'No pudimos cargar el perfil.') })
    return () => { active = false }
  }, [])
  const save = async (e: FormEvent) => { e.preventDefault(); if (profile) { const { name, biography, share_biography, version } = profile; await action.run('/api/v1/profile', { name, biography, share_biography, version }, leerPerfil, c => setProfile(leerPerfil(c))) } }
  const changePassword = async (e: FormEvent) => {
    e.preventDefault(); if (passwordFlight.current) return
    if (password !== confirmation) { setPasswordError('Las contraseñas nuevas deben coincidir.'); return }
    passwordFlight.current = true; setPasswordBusy(true); setPasswordError('')
    try { const r = datos(await workspaceRequest('/api/v1/profile/password', { current_password: current, password, password_confirmation: confirmation })); if (r.signed_out !== true) throw new ApiError(502, 'No pudimos confirmar el cambio.', {}, undefined, true); setCurrent(''); setPassword(''); setConfirmation(''); navigate('/ingresar', { replace: true }) }
    catch (err) { setPasswordError(err instanceof Error ? err.message : 'No pudimos cambiar la contraseña.'); if (err instanceof ApiError && err.incierto) setPasswordUncertain(true) }
    finally { passwordFlight.current = false; setPasswordBusy(false) }
  }
  return <Pagina migas="Área privada / Mi perfil" etiqueta="Cuenta personal" titulo="Mi perfil" bajada="Controla el nombre de tu cuenta, tu presentación y la seguridad de tu acceso.">
    <Link className={botonSecundario} to="/area">Volver al área privada</Link>
    {(sessionError || loadError) && <div className="mt-5"><Aviso tipo="error">{sessionError || loadError}</Aviso></div>}
    {!profile && !loadError && <p role="status" className="mt-5">Cargando perfil…</p>}
    {profile && <form onSubmit={save} className={panelWorkspace}><h2 className="text-xl font-bold text-navy">Datos de la cuenta</h2><fieldset disabled={action.locked || passwordBusy} className="space-y-4">
      <Field id="perfil-nombre" label="Nombre de la cuenta"><input id="perfil-nombre" required maxLength={160} className={inputCls(false)} value={profile.name} onChange={e => setProfile({ ...profile, name: e.target.value })} /></Field>
      <div><p className="text-sm font-bold">Correo de acceso</p><p className="break-all text-sm">{profile.email}</p></div>
      <Field id="perfil-biografia" label="Presentación profesional" hint="Hasta 1000 caracteres. Es privada salvo que elijas compartirla."><textarea id="perfil-biografia" maxLength={1000} rows={4} className={inputCls(false)} value={profile.biography} onChange={e => setProfile({ ...profile, biography: e.target.value })} /></Field>
      <label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1" checked={profile.share_biography} onChange={e => setProfile({ ...profile, share_biography: e.target.checked })} /><span>Compartir mi presentación con integrantes de mis comisiones.</span></label>
    </fieldset><Aviso>Tu nombre se muestra a las personas con las que colaboras. Estos cambios no modifican la identidad o representación registrada en una postulación.</Aviso><GuardarWorkspace action={action} url="/api/v1/profile">Guardar perfil</GuardarWorkspace><ResultadoAccion action={action} /></form>}
    {sesion && <form onSubmit={changePassword} className={panelWorkspace}><h2 className="text-xl font-bold text-navy">Cambiar contraseña</h2><p className="text-sm text-muted">Al confirmarla se cierra esta sesión y tendrás que ingresar nuevamente. Se revocan los accesos mediante tokens anteriores.</p>
      <fieldset disabled={passwordBusy || action.locked || passwordUncertain} className="space-y-4"><Field id="clave-actual" label="Contraseña actual"><input id="clave-actual" type="password" autoComplete="current-password" required className={inputCls(false)} value={current} onChange={e => setCurrent(e.target.value)} /></Field>
        <Field id="clave-nueva" label="Contraseña nueva" hint="Al menos 12 caracteres, con letras y números. Hasta 72 bytes."><input id="clave-nueva" type="password" autoComplete="new-password" required minLength={12} maxLength={72} className={inputCls(false)} value={password} onChange={e => setPassword(e.target.value)} /></Field>
        <Field id="clave-confirmacion" label="Repetir contraseña nueva"><input id="clave-confirmacion" type="password" autoComplete="new-password" required minLength={12} maxLength={72} className={inputCls(false)} value={confirmation} onChange={e => setConfirmation(e.target.value)} /></Field></fieldset>
      {passwordError && <Aviso tipo="error">{passwordError}</Aviso>}
      {passwordUncertain ? <Aviso tipo="warn">La respuesta del cambio no se confirmó. <Link className="underline" to="/ingresar">Vuelve a ingresar</Link> para comprobar tu acceso; también puedes recuperar la contraseña.</Aviso> : <button className={botonPrimario} disabled={passwordBusy || action.locked}>{passwordBusy ? 'Cambiando…' : 'Cambiar contraseña y cerrar sesión'}</button>}
    </form>}
  </Pagina>
}
