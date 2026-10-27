import { CircleX, LayoutDashboard } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import { useCurrentUser } from '../../../shared/model/useCurrentUser'
import {
  clampCustom,
  comparisonFor,
  dayCount,
  type PeriodSelection,
  parseSelection,
  rangeFor,
  searchWithSelection,
  todayAt,
} from '../lib/period'
import {
  DEFAULT_PREFERENCES,
  type DashboardPreferences,
  loadPreferences,
  preferencesKey,
  savePreferences,
} from '../lib/preferences'
import { WIDGETS } from '../lib/widgets'
import {
  type DashboardSummaryResponse,
  useDashboardSummary,
} from '../model/useDashboardSummary'
import { PeriodBar } from './PeriodBar'
import { WidgetCard } from './WidgetCard'
import { ProfitHint } from './ProfitHint'

interface StoredPreferences {
  key: string | null
  preferences: DashboardPreferences
}

function load(key: string | null): DashboardPreferences {
  return key === null ? DEFAULT_PREFERENCES : loadPreferences(key)
}

export function DashboardPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [search, setSearch] = useSearchParams()
  const currentUser = useCurrentUser()
  const [settingsOpen, setSettingsOpen] = useState(false)
  const settingsTrigger = useRef<HTMLButtonElement>(null)

  // Настройки — свои у каждой пары «пользователь, компания». При смене
  // компании ключ меняется, и сохранённое перечитывается тут же, в том же
  // рендере: иначе на мгновение показались бы виджеты прошлой компании.
  const key =
    companyId === undefined || currentUser.data === undefined
      ? null
      : preferencesKey(currentUser.data.email, companyId)
  const [stored, setStored] = useState<StoredPreferences>(() => ({
    key,
    preferences: load(key),
  }))
  const preferences = stored.key === key ? stored.preferences : load(key)
  if (stored.key !== key) {
    setStored({ key, preferences })
  }

  const updatePreferences = (next: DashboardPreferences): void => {
    setStored({ key, preferences: next })
    if (key !== null) {
      savePreferences(key, next)
    }
  }

  const today = todayAt()
  const selection = parseSelection(search, preferences.selection, today)
  const range = rangeFor(selection, today)
  const comparison = comparisonFor(selection, range)

  const select = (next: PeriodSelection): void => {
    setSearch(searchWithSelection(search, next), { replace: true })
    updatePreferences({ ...preferences, selection: next })
  }

  const query = useDashboardSummary(
    companyId ?? '',
    { current: range, comparison },
    { enabled: companyId !== undefined },
  )

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  if (companyId === undefined) {
    return (
      <Card tone="negative">
        <StatusPanel
          description="Откройте экран из списка компаний."
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Компания не выбрана"
          tone="negative"
        />
      </Card>
    )
  }

  const hidden = preferences.hidden
  const visible = WIDGETS.filter((widget) => !hidden.includes(widget.id))
  const setHidden = (next: readonly (typeof WIDGETS)[number]['id'][]): void => {
    updatePreferences({ ...preferences, hidden: next })
  }

  return (
    <section className="flex flex-col gap-4">
      <header className="flex flex-col gap-0.5">
        <h1 className="text-2xl font-semibold tracking-tight">Главная</h1>
        <p className="text-sm text-text-muted">
          Ozon · заказы и выкуп — по дате заказа, деньги — по дате начисления,
          как в отчёте «Начисления» кабинета.
        </p>
      </header>

      <PeriodBar
        comparison={comparison}
        compare={selection.compare}
        hidden={hidden}
        onCompare={(compare) => {
          select({ ...selection, compare })
        }}
        onCustom={(custom) => {
          // В адрес и настройки — та дата, которую примет API, а не
          // набранная: иначе ссылка разошлась бы с подписью диапазона.
          select({
            ...selection,
            preset: 'custom',
            custom: clampCustom(custom, today),
          })
        }}
        onHidden={setHidden}
        onPreset={(preset) => {
          select({
            ...selection,
            preset,
            custom: preset === 'custom' ? range : null,
          })
        }}
        onSettingsOpen={setSettingsOpen}
        preset={selection.preset}
        range={range}
        settingsOpen={settingsOpen}
        settingsTrigger={settingsTrigger}
        today={today}
      />

      {query.status === 'success' ? <Notes summary={query.data} /> : null}

      <Legend />

      {visible.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border-strong bg-surface-raised px-6 py-12 text-center">
          <span className="text-sm font-semibold">Все виджеты скрыты</span>
          <span className="max-w-90 text-sm leading-normal text-text-muted">
            Включите нужные показатели в настройках — выбор сохранится для этого
            кабинета.
          </span>
          <div className="mt-2">
            <Button
              onClick={() => {
                setHidden([])
              }}
              type="button"
            >
              Показать все виджеты
            </Button>
          </div>
        </div>
      ) : null}

      {visible.length > 0 && query.status === 'pending' ? (
        <div
          aria-busy="true"
          aria-label="Считаем показатели"
          className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
          {visible.map((widget) => (
            <div
              className="h-64 animate-pulse rounded-xl border border-border-default bg-surface-raised shadow-card"
              key={widget.id}
            />
          ))}
        </div>
      ) : null}

      {query.status === 'error' ? (
        <Card tone="negative">
          <StatusPanel
            action={
              <Button
                onClick={() => {
                  void query.refetch()
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Повторить
              </Button>
            }
            description={
              query.error instanceof Error
                ? query.error.message
                : 'Попробуйте обновить страницу.'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось посчитать показатели"
            tone="negative"
          />
        </Card>
      ) : null}

      {visible.length > 0 && query.status === 'success' ? (
        <div
          aria-busy={query.isPlaceholderData}
          className={`grid gap-4 sm:grid-cols-2 xl:grid-cols-4 ${query.isPlaceholderData ? 'opacity-60' : ''}`}
        >
          {visible.map((widget) => (
            <WidgetCard
              changes={query.data.changes}
              comparison={query.data.comparison.metrics}
              comparisonDays={query.data.comparison.days}
              currency={query.data.currency}
              current={query.data.current.metrics}
              currentDays={query.data.current.days}
              hint={
                widget.id === 'buyout' ? (
                  buyoutHint(query.data)
                ) : widget.id === 'profit' ? (
                  <ProfitHint
                    companyId={companyId}
                    period={query.data.current}
                  />
                ) : null
              }
              key={widget.id}
              onHide={() => {
                setHidden([...hidden, widget.id])
                // Карточка с кнопкой исчезает — фокус на «Виджеты»,
                // откуда скрытое возвращается.
                settingsTrigger.current?.focus()
              }}
              spec={widget}
            />
          ))}
        </div>
      ) : null}

      {query.status === 'success' &&
      query.data.current.metrics.ordersQuantity === 0 &&
      query.data.current.metrics.salesQuantity === 0 &&
      !query.data.current.profitDetails.hasAccruals &&
      visible.length > 0 ? (
        <Card>
          <StatusPanel
            description="За выбранный период нет ни заказов, ни начислений. Проверьте подключение кабинета или выберите другой период."
            icon={<LayoutDashboard aria-hidden="true" size={20} />}
            title="Пока показывать нечего"
          />
        </Card>
      ) : null}
    </section>
  )
}

/**
 * Когорта выкупа созревает неделями: пока исход известен не у всех
 * заказов периода, прогноз уточняется (ADR-029).
 */
function buyoutHint(summary: DashboardSummaryResponse): string | null {
  const resolution = summary.current.metrics.buyoutResolutionRateBps
  if (resolution === null || resolution === undefined || resolution >= 10_000) {
    return null
  }

  return `Исход известен по ${formatBasisPoints(resolution)} заказов — остальные ещё в пути, доля выкупа уточнится.`
}

function Notes({ summary }: { summary: DashboardSummaryResponse }) {
  const notes: string[] = []
  const { current, comparison } = summary

  if (current.daysWithoutAccruals > 0) {
    notes.push(
      `За ${String(current.daysWithoutAccruals)} дн. периода начисления ещё не загружены — продажи, возвраты, реклама и логистика за них занижены, прибыль недоступна.`,
    )
  }
  if (!current.dataComplete || !comparison.dataComplete) {
    // Сравнение с неполным периодом показало бы рост или падение,
    // которых не было, — поэтому изменения скрыты, а причина названа.
    const which = current.dataComplete ? 'Период сравнения' : 'Выбранный период'
    notes.push(
      `${which} загружен не полностью — часть дней не загружена или пришлась на время до подключения кабинета, поэтому изменения не показаны.`,
    )
  }
  if (dayCount(current) < 3) {
    notes.push('Для коротких периодов тренд на графиках менее информативен.')
  }

  if (notes.length === 0) {
    return null
  }

  return (
    <div
      className="flex flex-col gap-1 rounded-lg border border-border-subtle bg-surface-hover px-4 py-2.5 text-xs leading-normal text-text-muted"
      role="status"
    >
      {notes.map((note) => (
        <span key={note}>{note}</span>
      ))}
    </div>
  )
}

function Legend() {
  return (
    <div className="flex flex-wrap items-center gap-4 text-xs text-text-muted">
      <span className="inline-flex items-center gap-1.5">
        <span className="w-4 border-t-2 border-accent-default" />
        Выбранный период
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="w-4 border-t-2 border-dashed border-text-disabled" />
        Период сравнения
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="font-semibold text-positive-text">▲▼</span>
        зелёный — лучше,
        <span className="font-semibold text-negative-text">▲▼</span>
        красный — хуже, серый — нейтрально
      </span>
    </div>
  )
}
