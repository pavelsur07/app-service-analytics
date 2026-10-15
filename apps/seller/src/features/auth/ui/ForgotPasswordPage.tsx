import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEventHandler } from 'react'
import {
  InvisibleSmartCaptcha,
  useSmartCaptchaLoader,
} from '@yandex/smart-captcha'
import { CircleAlert, MailCheck } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'

import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import {
  acceptCaptcha,
  failCaptcha,
  failCaptchaLoader,
  resetCaptcha,
  retryCaptcha,
  showChallenge,
  startCaptcha,
} from '../lib/captchaFlow'
import type { CaptchaFlowState } from '../lib/captchaFlow'
import { scheduleCaptchaLoaderTimeout } from '../lib/captchaLoaderGuard'
import { requestResetFailure } from '../lib/passwordResetFailure'
import { useRequestPasswordReset } from '../model/usePasswordReset'

interface ForgotPasswordValues {
  email: string
}

type ForgotFlow = CaptchaFlowState<ForgotPasswordValues>

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const CAPTCHA_RETRY_MESSAGE =
  'Не удалось подтвердить проверку. Попробуйте ещё раз'
const CAPTCHA_UNAVAILABLE_MESSAGE =
  'Проверка временно недоступна. Попробуйте позже'
const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

// Запрос сброса пароля (ADR-037). Капча — тот же невидимый виджет
// и тот же автомат, что у регистрации. Экран после отправки один для
// любого адреса: сервер намеренно не сообщает, есть ли аккаунт.
export function ForgotPasswordPage() {
  const requestReset = useRequestPasswordReset()
  const [sent, setSent] = useState(false)
  const [captchaRevision, setCaptchaRevision] = useState(0)
  const [flow, setFlow] =
    useState<ForgotFlow>(resetCaptcha<ForgotPasswordValues>())
  const [failureMessage, setFailureMessage] = useState<string | null>(null)
  const flowRef = useRef<ForgotFlow>(flow)

  const updateFlow = useCallback((next: ForgotFlow) => {
    flowRef.current = next
    setFlow(next)
  }, [])

  const failProviderLoad = useCallback(() => {
    const current = flowRef.current
    const next = failCaptchaLoader(current, CAPTCHA_UNAVAILABLE_MESSAGE)

    if (next !== current) {
      updateFlow(next)
    }
  }, [updateFlow])
  const smartCaptcha = useSmartCaptchaLoader(undefined, failProviderLoad)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ForgotPasswordValues>()

  useEffect(() => {
    if (flow.status !== 'checking' || smartCaptcha !== undefined) {
      return
    }

    return scheduleCaptchaLoaderTimeout(failProviderLoad)
  }, [failProviderLoad, flow.status, smartCaptcha])

  const resetWidget = () => {
    setCaptchaRevision((revision) => revision + 1)
    updateFlow(resetCaptcha<ForgotPasswordValues>())
  }

  const failAndRemount = (message: string) => {
    const current = flowRef.current
    const next = failCaptcha(current, message)

    if (next === current) {
      return
    }

    setCaptchaRevision((revision) => revision + 1)
    updateFlow(next)
  }

  const failPendingProviderLoad = () => {
    if (flowRef.current.status !== 'checking') {
      return
    }

    setCaptchaRevision((revision) => revision + 1)
    updateFlow(failCaptchaLoader(flowRef.current, CAPTCHA_UNAVAILABLE_MESSAGE))
  }

  const submitWithCaptcha = (captchaToken: string) => {
    const current = flowRef.current
    const next = acceptCaptcha(current, captchaToken)

    if (next === current || next.status !== 'submitting') {
      return
    }

    updateFlow(next)
    requestReset.mutate(
      { email: next.request.email, captchaToken },
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
        onSettled: resetWidget,
      },
    )
  }

  const onSubmit: FormEventHandler<HTMLFormElement> = (event) => {
    const initialRetry = retryCaptcha(flowRef.current)

    if (initialRetry.action === 'reload-page') {
      event.preventDefault()
      window.location.reload()
      return
    }

    void handleSubmit((values) => {
      setFailureMessage(null)
      const retry = retryCaptcha(flowRef.current)

      if (retry.action === 'reload-page') {
        window.location.reload()
        return
      }

      updateFlow(startCaptcha(retry.state, { email: values.email.trim() }))
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

  const busy = flow.status === 'checking' || flow.status === 'submitting'
  const shownFailure = flow.status === 'failed' ? flow.message : failureMessage

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

            {smartCaptcha === undefined ? null : (
              <InvisibleSmartCaptcha
                key={captchaRevision}
                sitekey={import.meta.env.VITE_SMARTCAPTCHA_CLIENT_KEY}
                visible={flow.status === 'checking'}
                onChallengeVisible={() => {
                  updateFlow(showChallenge(flowRef.current))
                }}
                onChallengeHidden={() => {
                  failAndRemount(CAPTCHA_RETRY_MESSAGE)
                }}
                onNetworkError={() => {
                  failAndRemount(CAPTCHA_UNAVAILABLE_MESSAGE)
                }}
                onTokenExpired={() => {
                  failAndRemount(CAPTCHA_RETRY_MESSAGE)
                }}
                onJavascriptError={failPendingProviderLoad}
                onSuccess={submitWithCaptcha}
              />
            )}

            <Button type="submit" loading={busy}>
              {flow.status === 'failed'
                ? 'Попробовать ещё раз'
                : 'Отправить ссылку'}
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
