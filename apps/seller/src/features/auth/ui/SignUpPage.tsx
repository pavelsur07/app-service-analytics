import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEventHandler } from 'react'
import {
  InvisibleSmartCaptcha,
  useSmartCaptchaLoader,
} from '@yandex/smart-captcha'
import { CircleAlert } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router'

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
import {
  COMPANY_NAME_MAX_LENGTH,
  PASSWORD_MIN_LENGTH,
  toRegistrationPayload,
} from '../lib/registrationPayload'
import type { RegistrationFormValues } from '../lib/registrationPayload'
import { signupFailure } from '../lib/signupFailure'
import { useSignUp } from '../model/useSignUp'
import { LegalConsentField } from './LegalConsentField'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const CAPTCHA_RETRY_MESSAGE =
  'Не удалось подтвердить проверку. Попробуйте ещё раз'
const CAPTCHA_UNAVAILABLE_MESSAGE =
  'Проверка временно недоступна. Попробуйте позже'

const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

export function SignUpPage() {
  const navigate = useNavigate()
  const signUp = useSignUp()
  const [captchaRevision, setCaptchaRevision] = useState(0)
  const [flow, setFlow] = useState<CaptchaFlowState>(resetCaptcha())
  const [failureMessage, setFailureMessage] = useState<string | null>(null)
  const flowRef = useRef<CaptchaFlowState>(flow)

  const failProviderLoad = useCallback(() => {
    const current = flowRef.current
    const next = failCaptchaLoader(current, CAPTCHA_UNAVAILABLE_MESSAGE)

    if (next === current) {
      return
    }

    flowRef.current = next
    setFlow(next)
  }, [])
  const smartCaptcha = useSmartCaptchaLoader(undefined, failProviderLoad)
  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RegistrationFormValues>({
    defaultValues: { legalConsent: false },
  })

  useEffect(() => {
    if (flow.status !== 'checking' || smartCaptcha !== undefined) {
      return
    }

    return scheduleCaptchaLoaderTimeout(failProviderLoad)
  }, [failProviderLoad, flow.status, smartCaptcha])

  const updateFlow = (next: CaptchaFlowState) => {
    flowRef.current = next
    setFlow(next)
  }

  const transition = (
    reducer: (current: CaptchaFlowState) => CaptchaFlowState,
  ) => {
    updateFlow(reducer(flowRef.current))
  }

  const resetWidget = () => {
    setCaptchaRevision((revision) => revision + 1)
    updateFlow(resetCaptcha())
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
    const current = flowRef.current

    if (current.status !== 'checking') {
      return
    }

    const next = failCaptchaLoader(current, CAPTCHA_UNAVAILABLE_MESSAGE)

    setCaptchaRevision((revision) => revision + 1)
    updateFlow(next)
  }

  const submitWithCaptcha = (captchaToken: string) => {
    const current = flowRef.current
    const next = acceptCaptcha(current, captchaToken)

    if (next === current || next.status !== 'submitting') {
      return
    }

    updateFlow(next)
    signUp.mutate(
      { request: next.request, captchaToken },
      {
        onSuccess: () => {
          void navigate('/sign-up/email-sent', { replace: true })
        },
        onError: (error) => {
          const failure = signupFailure(error)

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
      const request = toRegistrationPayload(values)

      if (request === null) {
        setError('legalConsent', {
          message: 'Подтвердите согласие с условиями.',
        })
        return
      }

      setFailureMessage(null)
      const retry = retryCaptcha(flowRef.current)

      if (retry.action === 'reload-page') {
        window.location.reload()
        return
      }

      updateFlow(startCaptcha(retry.state, request))
    })(event)
  }

  const busy = flow.status === 'checking' || flow.status === 'submitting'
  const shownFailure = flow.status === 'failed' ? flow.message : failureMessage

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <form className="flex flex-col gap-4" noValidate onSubmit={onSubmit}>
            <h1 className="text-xl font-semibold">Регистрация</h1>
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
            <Input
              label="Пароль"
              type="password"
              autoComplete="new-password"
              disabled={busy}
              hint={`Не менее ${PASSWORD_MIN_LENGTH} символов`}
              error={errors.password?.message}
              {...register('password', {
                required: 'Введите пароль',
                minLength: {
                  value: PASSWORD_MIN_LENGTH,
                  message: `Пароль должен содержать не менее ${PASSWORD_MIN_LENGTH} символов.`,
                },
              })}
            />
            <Input
              label="Название компании"
              type="text"
              autoComplete="organization"
              disabled={busy}
              error={errors.companyName?.message}
              {...register('companyName', {
                validate: {
                  required: (value) =>
                    value.trim().length > 0 || 'Введите название компании.',
                  maxLength: (value) =>
                    value.trim().length <= COMPANY_NAME_MAX_LENGTH ||
                    `Название компании не должно превышать ${COMPANY_NAME_MAX_LENGTH} символов.`,
                },
              })}
            />
            <Controller
              control={control}
              name="legalConsent"
              rules={{
                validate: (value) =>
                  value || 'Подтвердите согласие с условиями.',
              }}
              render={({ field }) => (
                <LegalConsentField
                  checked={field.value}
                  disabled={busy}
                  error={errors.legalConsent?.message}
                  inputRef={field.ref}
                  name={field.name}
                  onBlur={field.onBlur}
                  onChange={field.onChange}
                />
              )}
            />

            {shownFailure === null ? null : (
              <Card tone="negative">
                <StatusPanel
                  icon={<CircleAlert aria-hidden="true" size={20} />}
                  title="Регистрация не отправлена"
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
                  transition(showChallenge)
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
                : 'Создать аккаунт'}
            </Button>
            <p className="text-center text-sm text-text-muted">
              Уже есть аккаунт?{' '}
              <Link className={LINK_CLASS} to="/login">
                Войти
              </Link>
            </p>
          </form>
        </Card>
      </div>
    </div>
  )
}
