import { Navigate, Outlet, useParams } from 'react-router'

import { RequireAuth } from './RequireAuth'
import { Sidebar } from './Sidebar'

/**
 * Оболочка всего, что внутри компании: сайдбар слева, экран справа.
 *
 * Это маршрут, а не компонент на странице. Причина в том, что companyId
 * уже первым сегментом пути (CLAUDE.md §1), и смена компании — тот же
 * путь с другим идентификатором: оболочке-маршруту для этого не нужно
 * ни состояние, ни знание о текущем экране. Заодно ни один экран
 * компании не может отрендериться без сайдбара — забыть обернуть
 * нечего.
 *
 * RequireAuth здесь один на все вложенные экраны, а не по разу
 * на каждом.
 */
export function CompanyLayout() {
  const { companyId } = useParams<{ companyId: string }>()

  if (companyId === undefined) {
    return <Navigate to="/companies" replace />
  }

  return (
    <RequireAuth>
      <div className="flex min-h-screen bg-bg-base">
        <Sidebar companyId={companyId} />
        <main className="min-w-0 flex-1">
          <Outlet />
        </main>
      </div>
    </RequireAuth>
  )
}
