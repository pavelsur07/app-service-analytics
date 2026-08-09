import type { ReactNode } from 'react'
import { Navigate } from 'react-router'
import { ApiError } from '../api/ApiError'
import { useCurrentUser } from '../features/auth/model/useCurrentUser'
import { Badge, Card } from '../../../../packages/ui/src'

// Единая проверка живой сессии перед защищёнными экранами — 401 от
// /api/auth/me уводит на /login, а не оставляет "тихий пустой экран"
// (ТЗ §6). Другие ошибки (сеть, 500) — не повод разлогинивать того, кто
// на самом деле вошёл, поэтому редирект только по факту именно 401.
export function RequireAuth({ children }: { children: ReactNode }) {
  const currentUser = useCurrentUser()

  if (currentUser.status === 'pending') {
    return <p className="p-6 text-text-muted">Загрузка…</p>
  }

  if (currentUser.status === 'error') {
    if (
      currentUser.error instanceof ApiError &&
      currentUser.error.status === 401
    ) {
      return <Navigate to="/login" replace />
    }

    return (
      <div className="p-6">
        <Card>
          <div className="flex items-center gap-3">
            <Badge tone="negative">✕ ошибка</Badge>
            <span className="text-text-secondary">
              Не удалось проверить сессию.
            </span>
          </div>
        </Card>
      </div>
    )
  }

  return <>{children}</>
}
