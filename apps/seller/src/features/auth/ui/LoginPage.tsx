import { useForm } from 'react-hook-form'
import { CircleAlert } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { useLogin } from '../model/useLogin'

interface LoginFormValues {
  email: string
  password: string
}

// Простая валидация встроенными правилами react-hook-form, без zod:
// два обязательных поля, сервер — единственный источник правды насчёт
// того, верны ли email и пароль вместе (ADR-007, одинаковая ошибка).
// Схема на входную форму такой сложности была бы лишним слоем.
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
        void navigate('/companies')
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
            <h1 className="text-xl font-semibold">Вход</h1>
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
            <p className="text-center text-sm text-text-muted">
              Нет аккаунта?{' '}
              <Link
                className="font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
                to="/sign-up"
              >
                Зарегистрироваться
              </Link>
            </p>
          </form>
        </Card>
      </div>
    </div>
  )
}
