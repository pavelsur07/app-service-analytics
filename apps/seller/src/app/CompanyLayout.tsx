import { Navigate, Outlet, useParams } from 'react-router'

import { RequireAuth } from './RequireAuth'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

/**
 * Оболочка всего, что внутри компании: шапка сверху, сайдбар слева,
 * экран справа.
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
      {/* Шапка на всю ширину над сайдбаром, а не внутри контента —
          как в ките (раздел 14, эталонные экраны): компания и свежесть
          данных не должны уезжать вместе со скроллом экрана. */}
      <div className="flex min-h-screen flex-col bg-bg-base">
        <Topbar companyId={companyId} />
        <div className="flex flex-1">
          <Sidebar />
          <main className="min-w-0 flex-1">
            <Outlet />
          </main>
        </div>
      </div>
    </RequireAuth>
  )
}
