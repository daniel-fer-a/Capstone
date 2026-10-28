import { Link } from 'react-router'
import { botonSecundario, Pagina } from '../components/ui'

export default function Pendiente({ titulo, detalle }: { titulo: string; detalle: string }) {
  return (
    <Pagina migas={titulo} etiqueta="En construcción" titulo={titulo} bajada={detalle}>
      <Link to="/" className={botonSecundario}>Volver al inicio</Link>
    </Pagina>
  )
}
