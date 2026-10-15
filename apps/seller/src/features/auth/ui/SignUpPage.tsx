import { useState } from 'react'
import type { FormEventHandler } from 'react'
import { InvisibleSmartCaptcha } from '@yandex/smart-captcha'
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
  COMPANY_NAME_MAX_LENGTH,
  PASSWORD_MIN_LENGTH,
  toRegistrationPayload,
} from '../lib/registrationPayload'
import type {
  RegistrationFormValues,
  RegistrationPayload,
} from '../lib/registrationPayload'
import { signupFailure } from '../lib/signupFailure'
import { useInvisibleCaptcha } from '../model/useInvisibleCaptcha'
import { useSignUp } from '../model/useSignUp'
import { LegalConsentField } from './LegalConsentField'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

export function SignUpPage() {
  const navigate = useNavigate()
  const signUp = useSignUp()
  const [failureMessage, setFailureMessage] = useState<string | null>(null)
  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RegistrationFormValues>({
    defaultValues: { legalConsent: false },
  })
  const captcha = useInvisibleCaptcha<RegistrationPayload>({
    submit: (request, captchaToken) => {
      signUp.mutate(
        { request, captchaToken },
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
      const request = toRegistrationPayload(values)

      if (request === null) {
        setError('legalConsent', {
          message: 'Подтвердите согласие с условиями.',
        })
        return
      }

      setFailureMessage(null)
      captcha.begin(request)
    })(event)
  }

  const busy = captcha.busy
  const shownFailure = captcha.failureMessage ?? failureMessage

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

            {captcha.widget === null ? null : (
              <InvisibleSmartCaptcha
                key={captcha.widget.key}
                {...captcha.widget.props}
              />
            )}

            <Button type="submit" loading={busy}>
              {captcha.failed ? 'Попробовать ещё раз' : 'Создать аккаунт'}
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
