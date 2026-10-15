import { useState } from 'react'
import type { ReactNode } from 'react'
import { CircleAlert, CircleCheck, Link2Off } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router'

import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { resetPasswordFailure } from '../lib/passwordResetFailure'
import { consumeBootstrappedPasswordResetToken } from '../lib/passwordResetToken'
import { PASSWORD_MIN_LENGTH } from '../lib/registrationPayload'
import { useResetPassword } from '../model/usePasswordReset'

interface ResetPasswordValues {
  password: string
  passwordRepeat: string
}

type ResetState = 'form' | 'changed' | 'invalid-link'

const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

// Ввод нового пароля по ссылке из письма (ADR-037). Токен забран
// из fragment до монтирования роутера и в адресе уже стёрт; здесь
// он живёт только в состоянии страницы. Сессия не открывается —
// после смены человек входит новым паролем.
export function ResetPasswordPage() {
  const [token] = useState(consumeBootstrappedPasswordResetToken)
  const [state, setState] = useState<ResetState>(
    token === null ? 'invalid-link' : 'form',
  )
  const [failureMessage, setFailureMessage] = useState<string | null>(null)
  const resetPassword = useResetPassword()
  const {
    register,
    handleSubmit,
    setError,
    getValues,
    formState: { errors },
  } = useForm<ResetPasswordValues>()

  if (state === 'changed') {
    return (
      <Screen>
        <StatusPanel
          icon={<CircleCheck aria-hidden="true" size={20} />}
          title="Пароль изменён"
          description="Все прежние сеансы завершены. Войдите с новым паролем."
          tone="accent"
          action={
            <Link className={LINK_CLASS} to="/login">
              Войти
            </Link>
          }
        />
      </Screen>
    )
  }

  if (state === 'invalid-link' || token === null) {
    return (
      <Screen>
        <StatusPanel
          icon={<Link2Off aria-hidden="true" size={20} />}
          title="Ссылка недействительна"
          description="Ссылка устарела или уже использована. Запросите новую."
          tone="warning"
          action={
            <Link className={LINK_CLASS} to="/forgot-password">
              Запросить новую ссылку
            </Link>
          }
        />
      </Screen>
    )
  }

  const onSubmit = handleSubmit((values) => {
    setFailureMessage(null)
    resetPassword.mutate(
      { token, password: values.password },
      {
        onSuccess: () => {
          setState('changed')
        },
        onError: (error) => {
          const failure = resetPasswordFailure(error)

          if (failure.kind === 'invalid-link') {
            setState('invalid-link')
            return
          }

          if (failure.kind === 'field') {
            setError(failure.field, { message: failure.message })
            return
          }

          setFailureMessage(failure.message)
        },
      },
    )
  })

  return (
    <Screen>
      <form
        className="flex flex-col gap-4"
        noValidate
        onSubmit={(event) => {
          void onSubmit(event)
        }}
      >
        <h1 className="text-xl font-semibold">Новый пароль</h1>
        <Input
          label="Новый пароль"
          type="password"
          autoComplete="new-password"
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
          label="Повторите пароль"
          type="password"
          autoComplete="new-password"
          error={errors.passwordRepeat?.message}
          {...register('passwordRepeat', {
            required: 'Повторите пароль',
            validate: (value) =>
              value === getValues('password') || 'Пароли не совпадают.',
          })}
        />
        {failureMessage === null ? null : (
          <Card tone="negative">
            <StatusPanel
              icon={<CircleAlert aria-hidden="true" size={20} />}
              title="Пароль не изменён"
              description={failureMessage}
              tone="negative"
              role="alert"
            />
          </Card>
        )}
        <Button type="submit" loading={resetPassword.isPending}>
          Сменить пароль
        </Button>
      </form>
    </Screen>
  )
}

function Screen({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>{children}</Card>
      </div>
    </div>
  )
}
