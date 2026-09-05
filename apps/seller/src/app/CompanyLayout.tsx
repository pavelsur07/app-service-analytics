import type { UseQueryResult } from '@tanstack/react-query'
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
 *   упал с ошибкой) → оболочка компании.
 *
 *   Ветка ошибки — осознанный компромисс, а не забытое умолчание.
 *   Альтернатива («не решено», как у pending) клала бы весь экран
 *   у КАЖДОЙ компании при любом транзиентном сбое одного
 *   вспомогательного запроса, а подключение есть у подавляющего
 *   большинства сессий — регулярная блокировка всех ради редкого сбоя
 *   хуже, чем редкий тупик у меньшинства.
 *
 *   Цена компромисса: у компании без единого подключения та же ошибка
 *   воспроизводит ровно тот тупик, ради устранения которого писался
 *   весь этот гейт — оболочка отрисуется, а на онбординг гейт не уведёт.
 *   Выход не потерян: пункт «Подключения» в сайдбаре (Sidebar.tsx)
 *   остаётся доступным из оболочки и ведёт на ConnectionsPage
 *   (features/connections/ui/ConnectionsPage.tsx) — у него свой повтор
 *   того же запроса («Повторить» на ветке ошибки) и своя ссылка
 *   на /onboarding в пустом состоянии. Тупик решается там, не здесь.
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
 *
 * Результат `useConnections` спускается в `CompanyShell` и `Topbar`
 * пропом, а не читается там повторным вызовом хука: два независимых
 * наблюдателя одного и того же ошибающегося запроса (свежесть в шапке
 * уже вызывала `useConnections` до этого гейта) заводили у чужой
 * компании настоящий цикл перезапросов — TanStack Query держит
 * `retry: false`, но два одновременных наблюдателя одной и той же
 * `error`-записи пересоздавали подписку друг за другом, и `/connections`
 * с `/api/auth/me` били по бэкенду десятками запросов в секунду, пока
 * `SalesFactsPage` не успевал увести на /companies. Один наблюдатель —
 * гейт — и проблема снята вместе с одним лишним запросом на экран.
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

  return <CompanyShell companyId={companyId} connections={connections} />
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

function CompanyShell({
  companyId,
  connections,
}: {
  companyId: string
  connections: UseQueryResult<ConnectionsResponse>
}) {
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
      <Topbar companyId={companyId} connections={connections} />
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
