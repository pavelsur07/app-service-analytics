import { useForm } from 'react-hook-form'
import { CircleAlert } from 'lucide-react'
import { Button, Input } from '../../../../../../packages/ui/src'
import { FormDialog } from '../../../shared/ui/FormDialog'
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
export function RegisterAccountForm({
  onCreated,
  onCancel,
}: {
  onCreated: () => void
  onCancel: () => void
}) {
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
        onCreated()
      },
    })
  })

  return (
    <FormDialog
      busy={register.isPending}
      onClose={onCancel}
      title="Новый аккаунт"
    >
      <form
        onSubmit={(event) => {
          void onSubmit(event)
        }}
        className="flex flex-col gap-4"
        noValidate
      >
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
        <div className="flex flex-wrap gap-2">
          <Button type="submit" loading={register.isPending}>
            Зарегистрировать
          </Button>
          <Button
            type="button"
            variant="secondary"
            disabled={register.isPending}
            onClick={onCancel}
          >
            Отмена
          </Button>
        </div>
      </form>
    </FormDialog>
  )
}
