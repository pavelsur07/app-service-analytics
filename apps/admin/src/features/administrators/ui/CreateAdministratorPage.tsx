import { useForm } from 'react-hook-form'
import { CircleAlert, CircleCheck, Lock } from 'lucide-react'
import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { useCurrentAdmin } from '../../../shared/model/useCurrentAdmin'
import { useCreateAdministrator } from '../model/useCreateAdministrator'

interface CreateAdministratorFormValues {
  email: string
  password: string
}

// Первый экран системного раздела (ADR-017): SuperAdmin заводит Admin.
//
// Длина пароля здесь не проверяется намеренно: предел задан на бэкенде
// (CreateAdministratorRequest::MIN_PASSWORD_LENGTH) и приходит в тексте
// отказа. Продублировать число тут значит завести второе место, которое
// однажды разойдётся с первым и начнёт врать в более строгую сторону.
export function CreateAdministratorPage() {
  const currentAdmin = useCurrentAdmin()
  const createAdministrator = useCreateAdministrator()
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CreateAdministratorFormValues>()

  const onSubmit = handleSubmit((values) => {
    createAdministrator.mutate(values, {
      onSuccess: () => {
        // Пароль не должен остаться в поле после отправки: экран
        // открыт ровно там, где рядом стоят люди.
        reset()
      },
    })
  })

  // Отказ всё равно придёт от бэкенда (#[IsGranted]) — здесь только
  // объяснение, почему формы нет. Спрятанная форма защитой не является.
  if (currentAdmin.data && currentAdmin.data.role !== 'super_admin') {
    return (
      <Card>
        <StatusPanel
          description="Заводить администраторов может только SuperAdmin."
          icon={<Lock aria-hidden="true" size={20} />}
          title="Недоступно"
        />
      </Card>
    )
  }

  return (
    <div className="max-w-sm">
      <Card>
        <form
          onSubmit={(event) => {
            void onSubmit(event)
          }}
          className="flex flex-col gap-4"
          noValidate
        >
          <h1 className="text-xl font-semibold">Новый администратор</h1>
          <Input
            label="Email"
            type="email"
            autoComplete="off"
            error={errors.email?.message}
            {...register('email', { required: 'Введите email' })}
          />
          <Input
            label="Пароль"
            type="password"
            autoComplete="new-password"
            error={errors.password?.message}
            {...register('password', { required: 'Введите пароль' })}
          />
          {createAdministrator.isError && (
            <div
              className="flex items-center gap-2 rounded-lg border border-negative-border bg-negative-bg p-3 text-xs text-negative-text"
              role="alert"
            >
              <CircleAlert aria-hidden="true" size={16} />
              <span>
                {createAdministrator.error instanceof Error
                  ? createAdministrator.error.message
                  : 'Не удалось завести администратора'}
              </span>
            </div>
          )}
          {createAdministrator.isSuccess && (
            <div
              className="flex items-center gap-2 rounded-lg border border-positive-border bg-positive-bg p-3 text-xs text-positive-text"
              role="status"
            >
              <CircleCheck aria-hidden="true" size={16} />
              <span>Заведён {createAdministrator.data.email}</span>
            </div>
          )}
          <Button type="submit" loading={createAdministrator.isPending}>
            Завести
          </Button>
        </form>
      </Card>
    </div>
  )
}
