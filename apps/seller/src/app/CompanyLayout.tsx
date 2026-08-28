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
          данных не должны уезжать вместе со скроллом экрана. Поэтому же
          оболочка ровно в высоту вьюпорта (h-screen, а не min-h-screen):
          прокручивается рабочая зона, а не документ.

          min-h-0 на строке выглядит лишним ровно до того, как его уберут:
          без него flex-ребёнок не сжимается ниже своего содержимого,
          main никогда не переполняется — и скролла не появляется
          нигде. */}
      <div className="flex h-screen flex-col overflow-hidden bg-bg-base">
        <Topbar companyId={companyId} />
        <div className="flex min-h-0 flex-1">
          <Sidebar />
          {/* Отступ и вертикальный ритм — свойство рабочей зоны, а не
              каждого экрана (docs/design/ui-kit/v0.4.html, раздел 13:
              padding 24px, gap 16px). Пока их проставляла каждая
              страница, забыть их было делом времени — и забыли
              на первом же новом экране. */}
          <main className="flex min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-6">
            <Outlet />
          </main>
        </div>
      </div>
    </RequireAuth>
  )
}
