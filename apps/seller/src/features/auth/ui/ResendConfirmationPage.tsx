import { useState } from 'react'
import { CircleX } from 'lucide-react'
import { useForm } from 'react-hook-form'

import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { useResendConfirmation } from '../model/useResendConfirmation'
import { EmailSentPage } from './EmailSentPage'

interface ResendFormValues {
  email: string
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function ResendConfirmationPage() {
  const [sent, setSent] = useState(false)
  const resend = useResendConfirmation()
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<ResendFormValues>()

  if (sent) {
    return (
      <EmailSentPage
        onResend={() => {
          resend.reset()
          setSent(false)
        }}
      />
    )
  }

  const onSubmit = handleSubmit((values) => {
    resend.mutate(values.email.trim(), {
      onSuccess: () => {
        reset()
        setSent(true)
      },
    })
  })

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <form
            className="flex flex-col gap-4"
            noValidate
            onSubmit={(event) => {
              void onSubmit(event)
            }}
          >
            <div className="flex flex-col gap-1">
              <h1 className="text-xl font-semibold">
                Отправить письмо ещё раз
              </h1>
              <p className="text-sm text-text-muted">
                Укажите адрес, который использовали при регистрации.
              </p>
            </div>
            <Input
              label="Email"
              type="email"
              autoComplete="email"
              error={errors.email?.message}
              {...register('email', {
                required: 'Введите email',
                pattern: {
                  value: EMAIL_PATTERN,
                  message: 'Введите корректный email.',
                },
              })}
            />
            {resend.isError ? (
              <Card tone="negative">
                <StatusPanel
                  icon={<CircleX aria-hidden="true" size={20} />}
                  title="Не удалось отправить письмо"
                  description="Попробуйте позже."
                  tone="negative"
                  role="alert"
                />
              </Card>
            ) : null}
            <Button type="submit" loading={resend.isPending}>
              Отправить
            </Button>
          </form>
        </Card>
      </div>
    </div>
  )
}
