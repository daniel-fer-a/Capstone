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

export default function App() {
  return (
    <PostulacionProvider>
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
          <Route path="seguimiento" element={<Pendiente titulo="Consultar postulación" detalle="Esta pantalla se construye en el siguiente bloque." />} />
          <Route path="ingresar" element={<Pendiente titulo="Acceso al área privada" detalle="El inicio de sesión se construye en el siguiente bloque." />} />
          <Route path="*" element={<Pendiente titulo="Página no encontrada" detalle="La dirección no existe." />} />
        </Route>
      </Routes>
    </PostulacionProvider>
  )
}
