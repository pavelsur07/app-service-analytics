import { useForm } from 'react-hook-form'
import { CircleAlert, CircleCheck } from 'lucide-react'
import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { useRegisterClientAccount } from '../model/useRegisterClientAccount'

interface RegisterAccountFormValues {
  name: string
  ownerEmail: string
  ownerPassword: string
}

// Компания и владелец одной формой, потому что и создаются они одной
// транзакцией (ADR-017). Раздельные шаги вернули бы состояние «компания
// без участников», ради устранения которого регистрация и сделана
// единым действием.
//
// Длина пароля здесь не проверяется: предел задан на бэкенде и приходит
// в тексте отказа. Второе место с тем же числом однажды разойдётся
// с первым.
export function RegisterAccountForm() {
  const register = useRegisterClientAccount()
  const {
    register: field,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<RegisterAccountFormValues>()

  const onSubmit = handleSubmit((values) => {
    register.mutate(values, {
      onSuccess: () => {
        reset()
      },
    })
  })

  return (
    <Card>
      <form
        onSubmit={(event) => {
          void onSubmit(event)
        }}
        className="flex flex-col gap-4"
        noValidate
      >
        <h2 className="text-lg font-semibold">Новый аккаунт</h2>
        <Input
          label="Название компании"
          error={errors.name?.message}
          {...field('name', { required: 'Введите название' })}
        />
        <Input
          label="Email владельца"
          type="email"
          autoComplete="off"
          error={errors.ownerEmail?.message}
          {...field('ownerEmail', { required: 'Введите email владельца' })}
        />
        <Input
          label="Пароль владельца"
          type="password"
          autoComplete="new-password"
          error={errors.ownerPassword?.message}
          {...field('ownerPassword', { required: 'Введите пароль' })}
        />
        {register.isError && (
          <div
            className="flex items-center gap-2 rounded-lg border border-negative-border bg-negative-bg p-3 text-xs text-negative-text"
            role="alert"
          >
            <CircleAlert aria-hidden="true" size={16} />
            <span>
              {register.error instanceof Error
                ? register.error.message
                : 'Не удалось зарегистрировать аккаунт'}
            </span>
          </div>
        )}
        {register.isSuccess && (
          <div
            className="flex items-center gap-2 rounded-lg border border-positive-border bg-positive-bg p-3 text-xs text-positive-text"
            role="status"
          >
            <CircleCheck aria-hidden="true" size={16} />
            <span>Зарегистрирован «{register.data.name}»</span>
          </div>
        )}
        <Button type="submit" loading={register.isPending}>
          Зарегистрировать
        </Button>
      </form>
    </Card>
  )
}
