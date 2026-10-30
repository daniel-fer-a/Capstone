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
import RouteFocus from './components/RouteFocus'

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
          <Route
            path="postular/juridica"
            element={<Pendiente titulo="Postulación de persona jurídica" detalle="Este recorrido se habilita cuando se defina cómo se identifican los dos socios patrocinadores." />}
          />
          <Route path="seguimiento" element={<Seguimiento />} />
          <Route path="ingresar" element={<Acceso />} />
          <Route path="recuperar" element={<Recuperar />} />
          <Route path="restablecer" element={<Restablecer />} />
          <Route path="area" element={<AreaPrivada />} />
          <Route path="*" element={<Pendiente titulo="Página no encontrada" detalle="La dirección no existe." />} />
        </Route>
      </Routes>
    </PostulacionProvider>
  )
}
