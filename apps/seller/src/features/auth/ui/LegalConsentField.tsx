import { useId } from 'react'
import type { ChangeEventHandler, FocusEventHandler, Ref } from 'react'

interface Props {
  checked: boolean
  disabled: boolean
  error?: string | undefined
  inputRef?: Ref<HTMLInputElement>
  name: string
  onBlur: FocusEventHandler<HTMLInputElement>
  onChange: ChangeEventHandler<HTMLInputElement>
}

const LINK_CLASS =
  'text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

export function LegalConsentField({
  checked,
  disabled,
  error,
  inputRef,
  name,
  onBlur,
  onChange,
}: Props) {
  const errorId = useId()

  return (
    <div className="flex flex-col gap-1">
      <label className="flex items-start gap-2 text-xs text-text-secondary">
        <input
          ref={inputRef}
          type="checkbox"
          name={name}
          checked={checked}
          disabled={disabled}
          onBlur={onBlur}
          onChange={onChange}
          aria-invalid={error === undefined ? undefined : true}
          aria-describedby={error === undefined ? undefined : errorId}
          className="mt-0.5 size-4 shrink-0 accent-accent-default"
        />
        <span>
          Я принимаю{' '}
          <a
            className={LINK_CLASS}
            href="https://conwix.com/privacy.html"
            target="_blank"
            rel="noreferrer"
          >
            политику конфиденциальности
          </a>
          ,{' '}
          <a
            className={LINK_CLASS}
            href="https://conwix.com/oferta.html"
            target="_blank"
            rel="noreferrer"
          >
            публичную оферту
          </a>{' '}
          и даю{' '}
          <a
            className={LINK_CLASS}
            href="https://conwix.com/personal-data.html"
            target="_blank"
            rel="noreferrer"
          >
            согласие на обработку персональных данных
          </a>
          .
        </span>
      </label>
      {error === undefined ? null : (
        <span className="text-xs text-negative-text" id={errorId}>
          {error}
        </span>
      )}
    </div>
  )
}
