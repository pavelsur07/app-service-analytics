import { useForm } from 'react-hook-form'
import { CircleAlert } from 'lucide-react'
import { useNavigate } from 'react-router'
import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { useLogin } from '../model/useLogin'

interface LoginFormValues {
  email: string
  password: string
}

// Валидация встроенными правилами react-hook-form, без zod: два
// обязательных поля, а верны ли они вместе — знает только сервер
// (ADR-007, одинаковая ошибка на «нет такого» и «неверный пароль»).
export function LoginPage() {
  const navigate = useNavigate()
  const login = useLogin()
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<LoginFormValues>()

  const onSubmit = handleSubmit((values) => {
    login.mutate(values, {
      onSuccess: () => {
        void navigate('/administrators')
      },
    })
  })

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <form
            onSubmit={(event) => {
              void onSubmit(event)
            }}
            className="flex flex-col gap-4"
            noValidate
          >
            <h1 className="text-xl font-semibold">Вход в администрирование</h1>
            <Input
              label="Email"
              type="email"
              autoComplete="username"
              error={errors.email?.message}
              {...register('email', { required: 'Введите email' })}
            />
            <Input
              label="Пароль"
              type="password"
              autoComplete="current-password"
              error={errors.password?.message}
              {...register('password', { required: 'Введите пароль' })}
            />
            {login.isError && (
              <div
                className="flex items-center gap-2 rounded-lg border border-negative-border bg-negative-bg p-3 text-xs text-negative-text"
                role="alert"
              >
                <CircleAlert aria-hidden="true" size={16} />
                <span>
                  {login.error instanceof Error
                    ? login.error.message
                    : 'Не удалось войти'}
                </span>
              </div>
            )}
            <Button type="submit" loading={login.isPending}>
              Войти
            </Button>
          </form>
        </Card>
      </div>
    </div>
  )
}
