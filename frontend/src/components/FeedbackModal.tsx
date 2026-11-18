import { useRef } from 'react'
import { botonPrimario, botonSecundario } from './estilos'
import { FEEDBACK_URL } from '../lib/feedback'

export default function FeedbackModal() {
  const ref = useRef<HTMLDialogElement>(null)
  return (
    <>
      <button type="button" onClick={() => ref.current?.showModal()} className="underline hover:text-navy">
        Danos tu opinión
      </button>
      <dialog ref={ref} aria-labelledby="fb-titulo" className="m-auto max-w-md rounded-xl border border-line bg-white p-6 text-navy backdrop:bg-black/40">
        <h2 id="fb-titulo" className="text-xl font-bold">Tu opinión nos ayuda</h2>
        <p className="mt-3 text-sm">
          Esta plataforma está en etapa de prueba. Si la usaste, cuéntanos cómo fue tu experiencia.
        </p>
        <ul className="mt-3 list-disc pl-5 text-sm">
          <li>Es anónimo: el formulario no pide nombre ni correo.</li>
          <li>El formulario indica que no solicita nombre, correo ni dirección IP.</li>
          <li>Esta plataforma no guarda ni envía tus respuestas.</li>
          <li>Se abre en una pestaña nueva.</li>
        </ul>
        <div className="mt-6 flex items-center justify-end gap-3">
          <button type="button" className={botonSecundario} onClick={() => ref.current?.close()}>Cerrar</button>
          <a className={botonPrimario} href={FEEDBACK_URL} target="_blank" rel="noopener noreferrer" onClick={() => ref.current?.close()}>
            Abrir formulario
          </a>
        </div>
      </dialog>
    </>
  )
}