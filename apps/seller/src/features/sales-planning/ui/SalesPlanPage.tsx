import { useEffect, useState } from 'react'
import { ArrowLeft, CircleX, PackagePlus } from 'lucide-react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { type Grouping, GROUPINGS } from '../lib/salesPlan'
import { useSalesPlan } from '../model/useSalesPlans'
import { SalesPlanLifecycle } from './SalesPlanLifecycle'
import { SalesPlanPerformance } from './SalesPlanPerformance'
import { SalesPlanCalendar } from './SalesPlanCalendar'
import { SalesPlanHeader } from './SalesPlanHeader'
import { SalesPlanGrid } from './SalesPlanGrid'
import { SalesPlanSkuForm } from './SalesPlanSkuForm'
import { SalesPlanDayForm } from './SalesPlanDayForm'
import { SalesPlanRemoveSkuButton } from './SalesPlanRemoveSkuButton'
import type { PickedDay } from './salesPlanEditorTypes'

/**
 * План продаж (ADR-040): шапка с итогами, SKU по корзинам выбранной
 * группировки и ввод SKU за период в черновик. Хранится день — неделя,
 * месяц и весь период считает сервер суммой дней.
 */
export function SalesPlanPage() {
  const { companyId, planId } = useParams<{
    companyId: string
    planId: string
  }>()

  // Ключ по компании и плану: курсор, группировка и форма ввода
  // принадлежат одному плану одной компании и при смене любого
  // из них начинаются заново.
  return <SalesPlanScreen key={`${companyId ?? ''}:${planId ?? ''}`} />
}

function SalesPlanScreen() {
  const navigate = useNavigate()
  const { companyId, planId } = useParams<{
    companyId: string
    planId: string
  }>()
  const [groupBy, setGroupBy] = useState<Grouping>('period')
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const cursor = cursorStack[cursorStack.length - 1] ?? null
  const [pickedDay, setPickedDay] = useState<PickedDay | null>(null)
  const [search, setSearch] = useSearchParams()
  const rawView = search.get('view')
  const selectedView =
    rawView === 'edit' || rawView === 'compare' ? rawView : null
  const setSelectedView = (view: 'edit' | 'compare') => {
    const next = new URLSearchParams(search)
    next.set('view', view)
    setSearch(next)
  }

  const plan = useSalesPlan(companyId ?? '', planId ?? '', groupBy, cursor)
  // Stable metadata subscription keeps the calendar mounted across SKU pages
  // and failed header rereads; the trusted plan stays in TanStack Query.
  const header = useSalesPlan(companyId ?? '', planId ?? '', 'period', null)
  const canCompare =
    plan.data !== undefined &&
    (plan.data.plan.status === 'approved' ||
      (plan.data.plan.status === 'draft' && plan.data.plan.versionNumber > 1))
  const showComparison =
    canCompare &&
    (selectedView ??
      (plan.data?.plan.status === 'approved' ? 'compare' : 'edit')) ===
      'compare'

  useEffect(() => {
    if (
      (plan.error instanceof ApiError && plan.error.status === 403) ||
      (header.error instanceof ApiError && header.error.status === 403)
    ) {
      void navigate('/companies', { replace: true })
    }
  }, [plan.error, header.error, navigate])

  if (companyId === undefined || planId === undefined) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <Link
        className="flex items-center gap-1 text-sm text-text-muted hover:text-text-primary"
        to={`/companies/${companyId}/sales-plans`}
      >
        <ArrowLeft aria-hidden="true" size={16} />
        Все планы
      </Link>

      {header.data !== undefined && (
        <>
          <SalesPlanHeader plan={header.data.plan} />
          <SalesPlanLifecycle
            companyId={companyId}
            plan={header.data.plan}
            onRevision={(id) => {
              void navigate(`/companies/${companyId}/sales-plans/${id}`)
            }}
          />
          <SalesPlanCalendar companyId={companyId} plan={header.data.plan} />
        </>
      )}

      {plan.status === 'pending' && (
        <Card>
          <div className="h-32 animate-pulse rounded bg-border-subtle" />
        </Card>
      )}

      {plan.status === 'error' && (
        <Card tone="negative">
          <StatusPanel
            description={
              plan.error instanceof Error
                ? plan.error.message
                : 'Неизвестная ошибка'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось загрузить план"
            tone="negative"
          />
        </Card>
      )}

      {plan.status === 'success' && (
        <>
          {canCompare ? (
            <div className="flex gap-2" role="group" aria-label="Раздел плана">
              <Button
                size="compact"
                variant={!showComparison ? 'primary' : 'secondary'}
                onClick={() => {
                  setSelectedView('edit')
                }}
              >
                Текущая версия
              </Button>
              <Button
                size="compact"
                variant={showComparison ? 'primary' : 'secondary'}
                onClick={() => {
                  setSelectedView('compare')
                }}
              >
                Выполнение исходной цели
              </Button>
            </div>
          ) : (
            plan.data.plan.status !== 'draft' && (
              <p className="text-sm text-text-muted">
                Историческая версия: только просмотр. Сравнение закрытой версии
                недоступно.
              </p>
            )
          )}
          {showComparison && (
            <SalesPlanPerformance
              key={`${companyId}:${planId}`}
              companyId={companyId}
              plan={plan.data.plan}
              onReread={() => plan.refetch()}
            />
          )}
          <div
            hidden={showComparison}
            className={showComparison ? 'hidden' : 'flex flex-col gap-4'}
          >
            {plan.data.plan.status === 'draft' && (
              <SalesPlanSkuForm companyId={companyId} plan={plan.data.plan} />
            )}

            <Card>
              <div
                className="mb-4 flex flex-wrap gap-2"
                role="group"
                aria-label="Группировка"
              >
                {GROUPINGS.map((option) => (
                  <Button
                    key={option.value}
                    type="button"
                    size="compact"
                    variant={groupBy === option.value ? 'primary' : 'secondary'}
                    aria-pressed={groupBy === option.value}
                    onClick={() => {
                      setGroupBy(option.value)
                      setCursorStack([null])
                      setPickedDay(null)
                    }}
                  >
                    {option.label}
                  </Button>
                ))}
              </div>

              {plan.data.skus.length === 0 ? (
                <StatusPanel
                  description="Добавьте SKU: штуки за период разложатся по дням поровну."
                  icon={<PackagePlus aria-hidden="true" size={20} />}
                  title="В плане пока нет SKU"
                  tone="neutral"
                />
              ) : (
                <SalesPlanGrid
                  currency={plan.data.plan.currency}
                  skus={plan.data.skus}
                  // Правится день, а не неделя: ячейка кликабельна только
                  // в группировке по дням и только у черновика.
                  onPickDay={
                    plan.data.plan.status === 'draft' && groupBy === 'day'
                      ? (cell) => {
                          setPickedDay({
                            ...cell,
                            version: plan.data.plan.version,
                          })
                        }
                      : null
                  }
                  removeAction={
                    plan.data.plan.status === 'draft'
                      ? (sku) => (
                          <SalesPlanRemoveSkuButton
                            companyId={companyId}
                            plan={plan.data.plan}
                            marketplaceSku={sku}
                          />
                        )
                      : null
                  }
                />
              )}

              {pickedDay !== null && plan.data.plan.status === 'draft' && (
                <SalesPlanDayForm
                  // Версия в ключе: повторный клик по тому же дню после чужой
                  // правки пересоздаёт форму — со свежими штуками и версией,
                  // а не со старыми полями при новой версии (ADR-008).
                  key={`${pickedDay.marketplaceSku}:${pickedDay.date}:${pickedDay.version}`}
                  companyId={companyId}
                  plan={plan.data.plan}
                  picked={pickedDay}
                  onClose={() => {
                    setPickedDay(null)
                  }}
                />
              )}

              {((plan.data.nextCursor ?? null) !== null ||
                cursorStack.length > 1) && (
                <div className="mt-4 flex items-center justify-end gap-2">
                  <Button
                    type="button"
                    variant="secondary"
                    size="compact"
                    disabled={cursorStack.length <= 1}
                    onClick={() => {
                      setCursorStack((stack) =>
                        stack.length > 1 ? stack.slice(0, -1) : stack,
                      )
                    }}
                  >
                    Назад
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    size="compact"
                    disabled={(plan.data.nextCursor ?? null) === null}
                    onClick={() => {
                      const next = plan.data.nextCursor ?? null
                      if (next !== null) {
                        setCursorStack((stack) => [...stack, next])
                      }
                    }}
                  >
                    Дальше
                  </Button>
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </div>
  )
}
