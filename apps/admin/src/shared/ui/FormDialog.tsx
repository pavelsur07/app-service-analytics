import { useEffect, useId, useRef } from 'react'
import type { ReactNode } from 'react'
import { X } from 'lucide-react'

import { Button } from '../../../../../packages/ui/src'

// showModal делает фон неактивным и удерживает клавиатурный фокус.
export function FormDialog({
  title,
  busy,
  onClose,
  children,
}: {
  title: string
  busy: boolean
  onClose: () => void
  children: ReactNode
}) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const titleId = useId()

  useEffect(() => {
    const dialog = dialogRef.current
    const trigger = document.activeElement
    dialog?.showModal()
    // React autoFocus срабатывает ещё до showModal, пока поле скрыто.
    dialog?.querySelector('input')?.focus()
    return () => {
      dialog?.close()
      if (trigger instanceof HTMLElement && trigger.isConnected) {
        trigger.focus()
      }
    }
  }, [])

  useEffect(() => {
    // Если нативное закрытие совпало с завершением запроса, сохраним ошибку
    // видимой до доставки отложенного браузером события close.
    const dialog = dialogRef.current
    if (dialog && !dialog.open) dialog.showModal()
  }, [busy])

  return (
    <dialog
      ref={dialogRef}
      aria-labelledby={titleId}
      className="m-auto max-h-11/12 w-11/12 max-w-lg overflow-y-auto rounded-xl border border-border-default bg-surface-raised p-5 text-text-primary shadow-modal backdrop:bg-text-primary/40"
      onCancel={(event) => {
        event.preventDefault()
        if (!busy) onClose()
      }}
      onClose={() => {
        // В StrictMode close из cleanup может прийти после нового showModal.
        if (dialogRef.current?.open) return
        if (busy) {
          dialogRef.current?.showModal()
        } else {
          onClose()
        }
      }}
    >
      <div className="mb-4 flex items-center justify-between gap-4">
        <h2 className="text-lg font-semibold" id={titleId}>
          {title}
        </h2>
        <Button
          aria-label="Закрыть"
          disabled={busy}
          onClick={onClose}
          size="compact"
          type="button"
          variant="ghost"
        >
          <X aria-hidden="true" size={20} />
        </Button>
      </div>
      {children}
    </dialog>
  )
}
