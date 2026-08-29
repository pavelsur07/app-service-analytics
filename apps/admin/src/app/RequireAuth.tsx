import type { ReactNode } from 'react'
import { CircleX, LoaderCircle } from 'lucide-react'
import { Navigate } from 'react-router'
import { ApiError } from '../api/ApiError'
import { useCurrentAdmin } from '../shared/model/useCurrentAdmin'
import { Button, Card, StatusPanel } from '../../../../packages/ui/src'

// Единая проверка живой сессии перед защищёнными экранами: 401
// от /api/admin/auth/me уводит на /login, а не оставляет пустой экран.
// Другие ошибки (сеть, 500) — не повод разлогинивать того, кто на самом
// деле вошёл, поэтому редирект строго по 401.
export function RequireAuth({ children }: { children: ReactNode }) {
  const currentAdmin = useCurrentAdmin()

  if (currentAdmin.status === 'pending') {
    return (
      <div className="p-6">
        <Card>
          <StatusPanel
            icon={
              <LoaderCircle
                aria-hidden="true"
                className="animate-spin"
                size={20}
              />
            }
            title="Проверяем сессию"
          />
        </Card>
      </div>
    )
  }

  if (currentAdmin.status === 'error') {
    if (
      currentAdmin.error instanceof ApiError &&
      currentAdmin.error.status === 401
    ) {
      return <Navigate to="/login" replace />
    }

    return (
      <div className="p-6">
        <Card tone="negative">
          <StatusPanel
            action={
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void currentAdmin.refetch()
                }}
              >
                Повторить
              </Button>
            }
            description="Не удалось проверить сессию."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Ошибка проверки сессии"
            tone="negative"
          />
        </Card>
      </div>
    )
  }

  return <>{children}</>
}
