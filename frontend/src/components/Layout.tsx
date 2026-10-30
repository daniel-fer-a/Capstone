import { Link, NavLink, Outlet } from 'react-router'
import logo from '../assets/logo-cchia.svg'
import logoSecundario from '../assets/logo-cchia-secundario.svg'

const enlace = ({ isActive }: { isActive: boolean }) =>
  `rounded-md px-3 py-2 text-sm ${isActive ? 'bg-tint font-semibold text-navy' : 'text-muted hover:text-navy'}`

export default function Layout() {
  return (
    <div className="flex min-h-screen flex-col">
      <a
        href="#contenido"
        className="absolute -top-24 left-3 z-50 rounded bg-white p-3 focus:top-3"
      >
        Saltar al contenido
      </a>
      <header className="border-b border-line bg-white">
        <div className="mx-auto flex max-w-5xl flex-col items-center justify-between gap-4 px-5 py-4 sm:flex-row">
          <Link to="/" aria-label="CCHIA, ir al inicio">
            <picture>
              <source media="(max-width: 767px)" srcSet={logoSecundario} />
              <img src={logo} alt="CCHIA, Cámara Chilena de Inteligencia Artificial" className="block h-auto w-[92px] md:w-[166px]" />
            </picture>
          </Link>
          <nav aria-label="Principal" className="flex flex-wrap items-center justify-center gap-1">
            <NavLink to="/" end className={enlace}>Inicio</NavLink>
            <NavLink to="/postular" className={enlace}>Postular</NavLink>
            <NavLink to="/seguimiento" className={enlace}>Seguimiento</NavLink>
            <Link
              to="/ingresar"
              className="ml-2 rounded-md border border-line px-4 py-2 text-sm font-bold text-navy hover:bg-tint"
            >
              Ingresar
            </Link>
          </nav>
        </div>
      </header>
      <main id="contenido" tabIndex={-1} className="min-w-0 flex-1">
        <Outlet />
      </main>
      <footer className="border-t border-line bg-white">
        <div className="mx-auto max-w-5xl px-5 py-4 text-xs text-muted">
          CCHIA · Postulación e ingreso de socios
        </div>
      </footer>
    </div>
  )
}
