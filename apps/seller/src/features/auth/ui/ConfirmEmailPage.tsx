import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { CircleAlert, CircleCheck, Link2Off, LoaderCircle } from 'lucide-react'
import { Link } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import {
  eraseConfirmationAddress,
  takeConfirmationToken,
} from '../lib/confirmationToken'
import { useConfirmEmail } from '../model/useConfirmEmail'
import type { ConfirmEmailState } from '../model/useConfirmEmail'

const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

interface ConfirmEmailViewProps {
  state: ConfirmEmailState
  onRetry(): void
}

export function ConfirmEmailView({ state, onRetry }: ConfirmEmailViewProps) {
  let content: ReactNode

  switch (state.kind) {
    case 'idle':
    case 'pending':
      content = (
        <StatusPanel
          icon={<LoaderCircle aria-hidden="true" size={20} />}
          title="Подтверждаем email"
          description="Это займёт несколько секунд."
          tone="accent"
        />
      )
      break
    case 'confirmed':
      content = (
        <StatusPanel
          icon={<CircleCheck aria-hidden="true" size={20} />}
          title="Email подтверждён"
          description="Переходим к настройке аккаунта."
          tone="accent"
        />
      )
      break
    case 'already-used':
      content = (
        <StatusPanel
          icon={<CircleAlert aria-hidden="true" size={20} />}
          title="Ссылка уже использована"
          description="Войдите в аккаунт, чтобы продолжить."
          action={
            <Link className={LINK_CLASS} to="/login">
              Войти
            </Link>
          }
          tone="warning"
        />
      )
      break
    case 'expired':
      content = (
        <StatusPanel
          icon={<Link2Off aria-hidden="true" size={20} />}
          title="Срок действия ссылки истёк"
          description="Запросите новое письмо с подтверждением."
          action={
            <Link className={LINK_CLASS} to="/resend-confirmation">
              Отправить письмо ещё раз
            </Link>
          }
          tone="warning"
        />
      )
      break
    case 'transient':
      content = (
        <StatusPanel
          icon={<CircleAlert aria-hidden="true" size={20} />}
          title="Временная ошибка"
          description="Проверьте соединение и попробуйте ещё раз."
          action={<Button onClick={onRetry}>Попробовать ещё раз</Button>}
          tone="negative"
          role="alert"
        />
      )
      break
    case 'missing':
      content = (
        <StatusPanel
          icon={<Link2Off aria-hidden="true" size={20} />}
          title="Ссылка недействительна"
          description="Запросите новое письмо с подтверждением."
          action={
            <Link className={LINK_CLASS} to="/resend-confirmation">
              Отправить письмо ещё раз
            </Link>
          }
          tone="negative"
          role="alert"
        />
      )
      break
    case 'failure':
      content = (
        <StatusPanel
          icon={<CircleAlert aria-hidden="true" size={20} />}
          title="Не удалось подтвердить email"
          description="Попробуйте запросить новое письмо позже."
          tone="negative"
          role="alert"
        />
      )
      break
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>{content}</Card>
      </div>
    </div>
  )
}

export function ConfirmEmailPage() {
  const { state, start, retry } = useConfirmEmail()

  useEffect(() => {
    const taken = takeConfirmationToken(window.location.href)

    eraseConfirmationAddress(window.history, taken.sanitizedPath)
    start(taken.token)
  }, [start])

  return <ConfirmEmailView state={state} onRetry={retry} />
}
