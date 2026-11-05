import { Route, Routes } from 'react-router'
import Layout from './components/Layout'
import { PostulacionProvider } from './postulacion/PostulacionProvider'
import Home from './pages/Home'
import Modalidad from './pages/Modalidad'
import DatosNatural from './pages/DatosNatural'
import Antecedentes from './pages/Antecedentes'
import Revision from './pages/Revision'
import Comprobante from './pages/Comprobante'
import Pendiente from './pages/Pendiente'
import Seguimiento from './pages/Seguimiento'
import Acceso from './pages/Acceso'
import Recuperar from './pages/Recuperar'
import Restablecer from './pages/Restablecer'
import AreaPrivada from './pages/AreaPrivada'
import RegistroSocios from './pages/RegistroSocios'
import GestionAvisos from './pages/GestionAvisos'
import RouteFocus from './components/RouteFocus'
import DatosJuridica from './pages/DatosJuridica'
import Patrocinios from './pages/Patrocinios'
import Continuar from './pages/Continuar'
import Documentos from './pages/Documentos'
import RevisionInstitucional from './pages/RevisionInstitucional'
import Invitaciones from './pages/Invitaciones'

export default function App() {
  return (
    <PostulacionProvider>
      <RouteFocus />
      <Routes>
        <Route element={<Layout />}>
          <Route index element={<Home />} />
          <Route path="postular" element={<Modalidad />} />
          <Route path="postular/natural/datos" element={<DatosNatural />} />
          <Route path="postular/natural/antecedentes" element={<Antecedentes />} />
          <Route path="postular/natural/revision" element={<Revision />} />
          <Route path="postular/comprobante" element={<Comprobante />} />
          <Route path="postular/juridica" element={<DatosJuridica />} />
          <Route path="postular/patrocinios" element={<Patrocinios />} />
          <Route path="postular/documentos" element={<Documentos />} />
          <Route path="postular/revision" element={<RevisionInstitucional />} />
          <Route path="continuar" element={<Continuar />} />
          <Route path="area/patrocinios" element={<Invitaciones />} />
          <Route path="seguimiento" element={<Seguimiento />} />
          <Route path="ingresar" element={<Acceso />} />
          <Route path="recuperar" element={<Recuperar />} />
          <Route path="restablecer" element={<Restablecer />} />
          <Route path="area" element={<AreaPrivada />} />
          <Route path="area/socios" element={<RegistroSocios />} />
          <Route path="area/avisos" element={<GestionAvisos />} />
          <Route path="*" element={<Pendiente titulo="Página no encontrada" detalle="La dirección no existe." />} />
        </Route>
      </Routes>
    </PostulacionProvider>
  )
}
