import { Navigate, Outlet, useParams } from 'react-router'

import type { components } from '../api/schema'
import { useConnections } from '../features/connections/model/useConnections'
import { RequireAuth } from './RequireAuth'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

type ConnectionsResponse = components['schemas']['ConnectionsResponse']

/**
 * Минимум, который гейту нужен от результата useConnections — не сам
 * UseQueryResult (там же isLoading, error и прочее сетевое), чтобы
 * решение проверялось тестами без монтирования хука и без сети.
 */
export type ConnectionsQueryState =
  | { status: 'pending' }
  | { status: 'error' }
  | { status: 'success'; data: ConnectionsResponse }

export type CompanyGateDecision =
  { kind: 'pending' } | { kind: 'onboarding'; to: string } | { kind: 'ready' }

/**
 * Решение гейта: что показать компании с этим списком подключений.
 * Вынесено из JSX в чистую функцию тем же приёмом, что
 * selectOnboardingCompany (features/onboarding/ui/OnboardingStartPage.tsx) —
 * «рендер не тестировать» (CLAUDE.md §9) про разметку, не про решение.
 *
 * - список подключений ещё не прочитан → решения нет: показать оболочку
 *   сейчас значит мигнуть пустым дашбордом и увести с него;
 * - список пуст → на онбординг, адрес несёт именно этот companyId
 *   (закодированным), иначе участник двух компаний ходит по кругу
 *   между гейтом и экраном выбора компании;
 * - иначе (есть хотя бы одно подключение, либо запрос списка сам
 *   упал) → оболочка компании. Собственная ошибка вспомогательного
 *   запроса списка подключений не имеет права положить весь экран —
 *   у списка подключений есть свой экран со своей обработкой ошибки
 *   (features/connections).
 */
export function resolveCompanyGate(
  companyId: string,
  connections: ConnectionsQueryState,
): CompanyGateDecision {
  if (connections.status === 'pending') {
    return { kind: 'pending' }
  }

  if (
    connections.status === 'success' &&
    connections.data.connections.length === 0
  ) {
    return {
      kind: 'onboarding',
      to: `/onboarding?company=${encodeURIComponent(companyId)}`,
    }
  }

  return { kind: 'ready' }
}

/**
 * Компания без активного подключения не имеет содержательного экрана
 * (ADR-021): показывать company-scoped экраны с пустыми списками
 * значит показывать нули, неотличимые от посчитанных. Гейт стоит
 * здесь, а не на каждом экране — забыть его в одном новом экране
 * вопрос времени, а последствие ровно те нули, ради отсутствия
 * которых он написан.
 *
 * Внутри RequireAuth, а не снаружи: иначе неаутентифицированный запрос
 * за подключениями получил бы 401 раньше, чем сработает редирект
 * на /login.
 */
function ConnectionGate({ companyId }: { companyId: string }) {
  const connections = useConnections(companyId)
  const decision = resolveCompanyGate(companyId, connections)

  if (decision.kind === 'pending') {
    return null
  }

  if (decision.kind === 'onboarding') {
    return <Navigate to={decision.to} replace />
  }

  return <CompanyShell companyId={companyId} />
}

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
      <ConnectionGate companyId={companyId} />
    </RequireAuth>
  )
}

function CompanyShell({ companyId }: { companyId: string }) {
  return (
    // Шапка на всю ширину над сайдбаром, а не внутри контента —
    // как в ките (раздел 14, эталонные экраны): компания и свежесть
    // данных не должны уезжать вместе со скроллом экрана. Поэтому же
    // оболочка ровно в высоту вьюпорта (h-screen, а не min-h-screen):
    // прокручивается рабочая зона, а не документ.
    //
    // min-h-0 на строке выглядит лишним ровно до того, как его уберут:
    // без него flex-ребёнок не сжимается ниже своего содержимого,
    // main никогда не переполняется — и скролла не появляется
    // нигде.
    <div className="flex h-screen flex-col overflow-hidden bg-bg-base">
      <Topbar companyId={companyId} />
      <div className="flex min-h-0 flex-1">
        <Sidebar />
        {/* Отступ и вертикальный ритм — свойство рабочей зоны, а не
            каждого экрана (docs/design/ui-kit/v0.6.html, раздел 13:
            padding 24px, gap 16px). Пока их проставляла каждая
            страница, забыть их было делом времени — и забыли
            на первом же новом экране. */}
        <main className="flex min-w-0 flex-1 flex-col gap-4 overflow-y-auto p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
