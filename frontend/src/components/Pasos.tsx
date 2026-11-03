const PASOS = ['Tus datos', 'Patrocinios', 'Documentos', 'Revisar y enviar', 'Comprobante']

export default function Pasos({ actual, piloto = false }: { actual: 1 | 2 | 3 | 4 | 5; piloto?: boolean }) {
  return (
    <aside className="md:w-56 md:shrink-0">
      <ol aria-label="Pasos de la postulación" className="space-y-1">
        {(piloto ? ['Tus datos', 'Antecedentes', 'Revisar y enviar', 'Comprobante'] : PASOS).map((nombre, i) => {
          const n = i + 1
          const hecho = n < actual
          const activo = n === actual
          return (
            <li
              key={nombre}
              aria-current={activo ? 'step' : undefined}
              className={`flex items-center gap-3 rounded-md px-3 py-2.5 text-sm ${
                activo ? 'bg-tint font-bold text-navy' : hecho ? 'text-ink' : 'text-muted'
              }`}
            >
              <span
                className={`grid h-7 w-7 shrink-0 place-items-center rounded-full border text-xs font-bold ${
                  activo
                    ? 'border-navy bg-navy text-white'
                    : hecho
                      ? 'border-ok/30 bg-ok-bg text-ok'
                      : 'border-line bg-white'
                }`}
              >
                {hecho ? '✓' : n}
              </span>
              {nombre}
            </li>
          )
        })}
      </ol>
      <p className="mt-5 px-3 text-xs text-muted">
        La recepción de una solicitud no otorga membresía ni crea una cuenta de acceso.
      </p>
    </aside>
  )
}
