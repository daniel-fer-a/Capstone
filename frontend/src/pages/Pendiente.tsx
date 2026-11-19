import { Link } from 'react-router'
import { Pagina } from '../components/ui'
import { botonSecundario } from '../components/estilos'

export default function Pendiente({ titulo, detalle }: { titulo: string; detalle: string }) {
  return (
    <Pagina migas={titulo} etiqueta="En construcción" titulo={titulo} bajada={detalle}>
      <Link to="/" className={botonSecundario}>Volver al inicio</Link>
    </Pagina>
  )
}
