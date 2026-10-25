import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Navigate, useNavigate } from 'react-router'

import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { apiGet, apiPost } from '../../../api/client'
import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'
import { useLogout } from '../model/useLogout'

type MfaStatus = components['schemas']['AdminMfaStatusResponse']
type Enrollment = components['schemas']['AdminMfaEnrollmentResponse']
type Completed = components['schemas']['AdminMfaCompletedResponse']

export function MfaPage({ settings = false }: { settings?: boolean }) {
  const navigate = useNavigate()
  const logout = useLogout()
  const queryClient = useQueryClient()
  const [code, setCode] = useState('')
  const status = useQuery({
    queryKey: adminQueryKey('mfa-status'),
    queryFn: () => apiGet<MfaStatus>('/api/admin/auth/mfa/status'),
    retry: false,
  })
  const enrollment = useMutation({
    mutationFn: () => apiPost<Enrollment>('/api/admin/auth/mfa/enroll'),
    gcTime: 0,
  })
  const completion = useMutation({
    mutationFn: (path: string) => apiPost<Completed>(path, { code }),
    gcTime: 0,
    onSuccess: (result) => {
      setCode('')
      enrollment.reset()
      if (result.recoveryCodes.length === 0) finish()
    },
  })

  function finish() {
    completion.reset()
    enrollment.reset()
    queryClient.clear()
    void navigate('/accounts', { replace: true })
  }

  if (status.isPending)
    return (
      <Card>
        <p>Проверяем второй фактор…</p>
      </Card>
    )
  if (status.isError) {
    if (status.error instanceof ApiError && status.error.status === 401) {
      return <Navigate to="/login" replace />
    }
    return (
      <Card>
        <p role="alert">Не удалось проверить второй фактор.</p>
        <Button onClick={() => void status.refetch()}>Повторить</Button>
      </Card>
    )
  }
  if (completion.data && completion.data.recoveryCodes.length > 0) {
    return (
      <Card>
        <div className="flex flex-col gap-4">
          <h1 className="text-xl font-semibold">Сохраните резервные коды</h1>
          <p>
            Каждый код работает один раз. Сохраните их в надёжном месте:
            повторно показать этот набор нельзя.
          </p>
          <ul aria-label="Резервные коды" className="flex flex-col gap-2">
            {completion.data.recoveryCodes.map((value) => (
              <li key={value}>
                <code>{value}</code>
              </li>
            ))}
          </ul>
          <Button onClick={finish}>Коды сохранены, продолжить</Button>
        </div>
      </Card>
    )
  }
  const state = status.data.state
  if (state === 'authenticated' && !settings)
    return <Navigate to="/accounts" replace />
  const enrolling = state === 'enrollment_required'
  const regenerating = state === 'authenticated'
  const error = completion.error ?? enrollment.error ?? logout.error

  return (
    <div className="mx-auto flex w-full max-w-sm flex-col gap-4 p-6">
      <Card>
        <div className="flex flex-col gap-4">
          <h1 className="text-xl font-semibold">
            {enrolling
              ? 'Настройка второго фактора'
              : regenerating
                ? 'Безопасность аккаунта'
                : 'Подтвердите вход'}
          </h1>
          {enrolling && !enrollment.data ? (
            <>
              <p>
                Для доступа к администрированию подключите
                приложение-аутентификатор.
              </p>
              <Button
                loading={enrollment.isPending}
                onClick={() => enrollment.mutate()}
              >
                Настроить второй фактор
              </Button>
            </>
          ) : (
            <form
              className="flex flex-col gap-4"
              onSubmit={(event) => {
                event.preventDefault()
                completion.mutate(
                  regenerating
                    ? '/api/admin/auth/mfa/recovery-codes'
                    : enrolling
                      ? '/api/admin/auth/mfa/confirm'
                      : '/api/admin/auth/mfa/verify',
                )
              }}
            >
              {enrollment.data && (
                <>
                  <p>
                    Добавьте Conwix в приложении-аутентификаторе с этим
                    секретом, затем введите шестизначный код.
                  </p>
                  <Input
                    label="Секрет аутентификатора"
                    value={enrollment.data.secret}
                    readOnly
                    autoComplete="off"
                  />
                </>
              )}
              {regenerating && (
                <p>
                  Новый набор отменит прежние резервные коды и завершит другие
                  сеансы. Подтвердите действие свежим кодом.
                </p>
              )}
              <Input
                label={
                  enrolling
                    ? 'Код аутентификатора'
                    : 'Код аутентификатора или резервный код'
                }
                value={code}
                onChange={(event) => setCode(event.target.value)}
                autoComplete="one-time-code"
                required
                maxLength={32}
              />
              <Button type="submit" loading={completion.isPending}>
                {regenerating ? 'Перевыпустить резервные коды' : 'Подтвердить'}
              </Button>
            </form>
          )}
          {error && (
            <p role="alert">
              {error instanceof Error
                ? error.message
                : 'Не удалось подтвердить второй фактор.'}
            </p>
          )}
          <Button
            variant="secondary"
            loading={logout.isPending}
            onClick={() => {
              enrollment.reset()
              completion.reset()
              logout.mutate()
            }}
          >
            Выйти
          </Button>
        </div>
      </Card>
    </div>
  )
}
