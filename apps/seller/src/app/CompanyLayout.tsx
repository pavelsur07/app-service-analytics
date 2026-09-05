import type { UseQueryResult } from '@tanstack/react-query'
import { Navigate, Outlet, useLocation, useParams } from 'react-router'

import type { components } from '../api/schema'
import { useConnections } from '../features/connections/model/useConnections'
import { RequireAuth } from './RequireAuth'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

type ConnectionsResponse = components['schemas']['ConnectionsResponse']
type ConnectionResponse = components['schemas']['ConnectionResponse']

function isActiveConnection(connection: ConnectionResponse): boolean {
  return connection.state === 'active'
}

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
  | { kind: 'pending' }
  | { kind: 'onboarding'; to: string }
  | { kind: 'connections'; to: string }
  | { kind: 'ready' }

function connectionsPath(companyId: string): string {
  return `/companies/${encodeURIComponent(companyId)}/connections`
}

/**
 * Решение гейта: что показать компании с этим списком подключений.
 * Вынесено из JSX в чистую функцию тем же приёмом, что
 * selectOnboardingCompany (features/onboarding/ui/OnboardingStartPage.tsx) —
 * «рендер не тестировать» (CLAUDE.md §9) про разметку, не про решение.
 *
 * - список подключений ещё не прочитан → решения нет: показать оболочку
 *   сейчас значит мигнуть пустым дашбордом и увести с него;
 * - запрос списка сам упал с ошибкой → оболочка компании (`ready`).
 *   Осознанный компромисс, а не забытое умолчание: альтернатива
 *   («не решено», как у pending) клала бы весь экран у КАЖДОЙ компании
 *   при любом транзиентном сбое одного вспомогательного запроса,
 *   а подключение есть у подавляющего большинства сессий — регулярная
 *   блокировка всех ради редкого сбоя хуже, чем редкий тупик
 *   у меньшинства. Цена компромисса: у компании без единого подключения
 *   та же ошибка воспроизводит тот тупик, ради устранения которого
 *   писался весь этот гейт. Выход не потерян: пункт «Подключения»
 *   в сайдбаре (Sidebar.tsx) остаётся доступным из оболочки и ведёт
 *   на ConnectionsPage — у него свой повтор того же запроса
 *   («Повторить» на ветке ошибки);
 * - подключений нет вовсе (список пуст) → на онбординг, адрес несёт
 *   именно этот companyId (закодированным), иначе участник двух
 *   компаний ходит по кругу между гейтом и экраном выбора компании.
 *   Онбординг здесь уместен: нет ни одной пары учётных данных,
 *   которую можно было бы чинить, — заводить кабинет ещё только
 *   предстоит;
 * - подключения есть, но ни одного `active` (только `broken`/`revoked`)
 *   → на экран подключений этой компании, а НЕ на онбординг. До июля
 *   2026 сюда тоже вела ветка «на онбординг» (тем же поводом — ADR-021,
 *   «нет активного» неотличимо от рабочей синхронизации), и это было
 *   ловушкой: онбординг не принимает повторную заявку на тот же кабинет
 *   («broken» — не «revoked», частичный уникальный индекс держит его),
 *   возвращает 409, а форма замены ключа, которая единственная умеет
 *   вернуть сломанное подключение в `active`
 *   (`ReplaceOzonCredentialsAction`), живёт на company-scoped экране
 *   «Подключения» — том самом, откуда этот же гейт разворачивает
 *   клиента раньше, чем он до формы доберётся. Починка ключа — не то
 *   же действие, что первое подключение, и вести их на один и тот же
 *   экран значит запирать клиента между гейтом и 409;
 *
 *   этой ветке нужен текущий адрес (третий параметр), а не просто
 *   решение по списку подключений: экран подключений сам company-scoped
 *   и рендерится ВНУТРИ этой же оболочки, то есть за этим же гейтом.
 *   Без проверки адреса решение «веди на /connections» сработало бы
 *   и когда клиент уже там — гейт увёл бы на /connections, там опять
 *   сработал бы тот же гейт с тем же списком подключений, снова увёл
 *   бы на /connections, и так по кругу. Поэтому: уже на экране
 *   подключений → `ready` (форма замены ключа успевает отрисоваться
 *   и сделать своё дело), где угодно ещё → редирект туда. Параметр —
 *   значение, а не хук: функция остаётся чистой и проверяется тестами
 *   без DOM (CLAUDE.md §10, окружение тестов `node`-only);
 * - иначе (есть хотя бы одно `active` подключение) → оболочка компании.
 */
export function resolveCompanyGate(
  companyId: string,
  connections: ConnectionsQueryState,
  pathname: string,
): CompanyGateDecision {
  if (connections.status === 'pending') {
    return { kind: 'pending' }
  }

  if (connections.status === 'success') {
    const hasActive = connections.data.connections.some(isActiveConnection)

    if (!hasActive) {
      if (connections.data.connections.length === 0) {
        return {
          kind: 'onboarding',
          to: `/onboarding?company=${encodeURIComponent(companyId)}`,
        }
      }

      const to = connectionsPath(companyId)
      if (pathname === to) {
        return { kind: 'ready' }
      }

      return { kind: 'connections', to }
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
  const { pathname } = useLocation()
  const decision = resolveCompanyGate(companyId, connections, pathname)

  if (decision.kind === 'pending') {
    return null
  }

  if (decision.kind === 'onboarding' || decision.kind === 'connections') {
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
