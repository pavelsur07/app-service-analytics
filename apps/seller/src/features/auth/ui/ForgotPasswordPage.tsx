import { useState } from 'react'
import type { FormEventHandler } from 'react'
import { InvisibleSmartCaptcha } from '@yandex/smart-captcha'
import { CircleAlert, MailCheck } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'

import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { requestResetFailure } from '../lib/passwordResetFailure'
import { useInvisibleCaptcha } from '../model/useInvisibleCaptcha'
import { useRequestPasswordReset } from '../model/usePasswordReset'

interface ForgotPasswordValues {
  email: string
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

// Запрос сброса пароля (ADR-037). Капча — общий хук useInvisibleCaptcha,
// тот же, что у регистрации. Экран после отправки один для
// любого адреса: сервер намеренно не сообщает, есть ли аккаунт.
export function ForgotPasswordPage() {
  const requestReset = useRequestPasswordReset()
  const [sent, setSent] = useState(false)
  const [failureMessage, setFailureMessage] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ForgotPasswordValues>()
  const captcha = useInvisibleCaptcha<ForgotPasswordValues>({
    submit: (request, captchaToken) => {
      requestReset.mutate(
        { email: request.email, captchaToken },
        {
          onSuccess: () => {
            setSent(true)
          },
          onError: (error) => {
            const failure = requestResetFailure(error)

            if (failure.kind === 'field') {
              setError(failure.field, { message: failure.message })
              return
            }

            setFailureMessage(failure.message)
          },
          onSettled: () => {
            captcha.settle()
          },
        },
      )
    },
  })

  const onSubmit: FormEventHandler<HTMLFormElement> = (event) => {
    if (!captcha.beforeSubmit(event)) {
      return
    }

    void handleSubmit((values) => {
      setFailureMessage(null)
      captcha.begin({ email: values.email.trim() })
    })(event)
  }

  if (sent) {
    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div className="w-full max-w-sm">
          <Card>
            <StatusPanel
              icon={<MailCheck aria-hidden="true" size={20} />}
              title="Проверьте почту"
              description="Если аккаунт с этим адресом существует, мы отправили ссылку для смены пароля. Она действует 1 час. Проверьте папки «Входящие» и «Спам»."
              tone="accent"
              action={
                <Link className={LINK_CLASS} to="/login">
                  Вернуться ко входу
                </Link>
              }
            />
          </Card>
        </div>
      </div>
    )
  }

  const busy = captcha.busy
  const shownFailure = captcha.failureMessage ?? failureMessage

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <form className="flex flex-col gap-4" noValidate onSubmit={onSubmit}>
            <div className="flex flex-col gap-1">
              <h1 className="text-xl font-semibold">Восстановление пароля</h1>
              <p className="text-sm text-text-muted">
                Укажите адрес, с которым входите в Conwix. Мы отправим ссылку
                для смены пароля.
              </p>
            </div>
            <Input
              label="Email"
              type="email"
              autoComplete="email"
              disabled={busy}
              error={errors.email?.message}
              {...register('email', {
                required: 'Введите email',
                pattern: {
                  value: EMAIL_PATTERN,
                  message: 'Введите корректный email.',
                },
              })}
            />

            {shownFailure === null ? null : (
              <Card tone="negative">
                <StatusPanel
                  icon={<CircleAlert aria-hidden="true" size={20} />}
                  title="Письмо не отправлено"
                  description={shownFailure}
                  tone="negative"
                  role="alert"
                />
              </Card>
            )}

            {captcha.widget === null ? null : (
              <InvisibleSmartCaptcha
                key={captcha.widget.key}
                {...captcha.widget.props}
              />
            )}

            <Button type="submit" loading={busy}>
              {captcha.failed ? 'Попробовать ещё раз' : 'Отправить ссылку'}
            </Button>
            <p className="text-center text-sm text-text-muted">
              <Link className={LINK_CLASS} to="/login">
                Вернуться ко входу
              </Link>
            </p>
          </form>
        </Card>
      </div>
    </div>
  )
}
