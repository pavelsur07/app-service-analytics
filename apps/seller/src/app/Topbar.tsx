import { Check, ChevronDown, CircleX, Clock } from 'lucide-react'
import { NavLink } from 'react-router'

import { useCurrentUser } from '../features/auth/model/useCurrentUser'
import { dataFreshness } from '../features/connections/lib/dataFreshness'
import type { DataFreshness } from '../features/connections/lib/dataFreshness'
import { useConnections } from '../features/connections/model/useConnections'

// Шапка — эталон docs/design/ui-kit/v0.4.html, разделы 10 и 14: высота 56,
// поверхность surface/raised, нижняя граница border/default, отступы 24,
// шаг 16. Логотип 28, переключатель компании 36, аватар 32.
const CHIP =
  'inline-flex h-7 items-center gap-2 rounded-full border px-2.5 text-xs'

const CHIP_TONE: Record<DataFreshness['tone'], string> = {
  neutral: 'border-border-default bg-surface-raised text-text-secondary',
  warning: 'border-warning-border bg-warning-bg text-warning-text',
  negative: 'border-negative-border bg-negative-bg text-negative-text',
}

const CHIP_ICON: Record<DataFreshness['tone'], typeof Check> = {
  neutral: Check,
  warning: Clock,
  negative: CircleX,
}

/**
 * Что из кита сюда не переносилось и почему:
 *
 * - период «01.08 — 10.08 · vs июль» — сквозного выбора периода в продукте
 *   пока нет, каждый экран задаёт своё окно сам. Шапка не место, где его
 *   заводить: это отдельная задача с состоянием и параметрами запросов;
 * - «Синхронизация Ozon · 62%» — прогресса загрузки бэкенд не отдаёт,
 *   а рисовать процент, взятый из воздуха, хуже, чем не рисовать его;
 * - колокольчик уведомлений — уведомления сегодня приходят письмом
 *   (ADR-007), списка в интерфейсе нет.
 *
 * Переключатель компании — ссылка на уже существующий экран выбора,
 * а не выпадающий список: у первого клиента компания одна, а поповера
 * в packages/ui нет намеренно (docs/patterns.md, «Чего в UI Kit нет»).
 */
export function Topbar({ companyId }: { companyId: string }) {
  const currentUser = useCurrentUser()
  const connections = useConnections(companyId)

  const company = currentUser.data?.companies.find(
    (candidate) => candidate.id === companyId,
  )
  const companyName = company?.name ?? 'Компания'

  // За «сейчас» берётся момент ответа, а не Date.now() в рендере: тот
  // запрещён линтером как нечистый вызов, а dataUpdatedAt — то же время
  // с точностью до сетевой задержки и обновляется сам при перезапросе,
  // в том числе по возврату на вкладку.
  //
  // ponytail: между перезапросами подпись не тикает — «2 ч назад» останется
  // «2 ч назад» до следующего ответа. Понадобится минутная точность —
  // добавить таймер, пересчитывающий возраст.
  const freshness =
    connections.data === undefined
      ? undefined
      : dataFreshness(connections.data.connections, connections.dataUpdatedAt)

  const FreshnessIcon =
    freshness === undefined ? Check : CHIP_ICON[freshness.tone]

  return (
    <header className="flex h-14 items-center gap-4 border-b border-border-default bg-surface-raised px-6">
      <span
        aria-hidden="true"
        className="grid size-7 place-items-center rounded-md bg-accent-default text-sm font-bold text-text-inverse"
      >
        C
      </span>

      <NavLink
        to="/companies"
        className="inline-flex h-9 items-center gap-2 rounded-md border border-border-default px-3 text-sm font-medium text-text-primary no-underline hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
      >
        <span
          aria-hidden="true"
          className="grid size-5 place-items-center rounded bg-accent-subtle text-xs font-bold text-accent-hover"
        >
          {companyName.slice(0, 1).toUpperCase()}
        </span>
        <span className="max-w-64 truncate">{companyName}</span>
        <ChevronDown aria-hidden="true" className="text-text-muted" size={14} />
      </NavLink>

      {/* Свежесть данных — основное обещание продукта, и место ей рядом
          с компанией, а не в футере (кит, раздел 10). Пока подключения
          не загрузились, места под индикатор не занимаем: прыгающая
          шапка хуже, чем индикатор, появившийся на полсекунды позже. */}
      {freshness !== undefined && (
        <span className={`${CHIP} ${CHIP_TONE[freshness.tone]} tabular-nums`}>
          <FreshnessIcon aria-hidden="true" size={14} />
          {freshness.text}
        </span>
      )}

      <span
        className="ml-auto grid size-8 place-items-center rounded-full bg-accent-subtle text-xs font-semibold text-accent-hover"
        title={currentUser.data?.email}
      >
        {(currentUser.data?.email ?? '?').slice(0, 2).toUpperCase()}
      </span>
    </header>
  )
}
