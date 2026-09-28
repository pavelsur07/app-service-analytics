import { Fragment, useEffect, useState } from 'react'
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleX,
  Scale,
  TriangleAlert,
} from 'lucide-react'
import { useNavigate, useSearchParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import {
  canGoBack,
  canGoForward,
  currentMonth,
  monthFromParam,
  monthLabel,
  shiftMonth,
} from '../../../shared/lib/month'
import { useAccrualReconciliation } from '../model/useAccrualReconciliation'

/**
 * Сверка с кабинетом: начисления Ozon за календарный месяц по группам
 * отчёта «Начисления», без товаров (ADR-036).
 *
 * Месяц, а не окно в днях: выгрузку кабинета сверяют месяцами.
 * По умолчанию — прошлый месяц, закрытый: текущий ещё пополняется.
 * Итог к начислению — то число, что клиент видит в выгрузке; его
 * совпадение и есть проверка, что данные загружены полностью.
 */
export function AccrualReconciliationPanel({
  companyId,
}: {
  companyId: string
}) {
  const navigate = useNavigate()
  const [search, setSearch] = useSearchParams()
  const [open, setOpen] = useState<ReadonlySet<string>>(new Set())

  const current = currentMonth()
  const month = monthFromParam(
    search.get('month'),
    current,
    shiftMonth(current, -1),
  )
  const query = useAccrualReconciliation(companyId, month)
  // Валюты нет только у пустого месяца (ADR-004): тогда и таблицы нет.
  const currency =
    query.status === 'success' ? (query.data.currency ?? null) : null

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  const setMonth = (next: string) => {
    const merged = new URLSearchParams(search)
    merged.set('month', next)
    setSearch(merged, { replace: true })
  }

  const toggle = (code: string) => {
    setOpen((previous) => {
      const next = new Set(previous)
      if (next.has(code)) {
        next.delete(code)
      } else {
        next.add(code)
      }
      return next
    })
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-text-muted">
          Начисления Ozon по дате начисления, как в отчёте «Начисления»
          кабинета. Итог к начислению сверяется с выгрузкой кабинета за тот же
          месяц.
        </p>
        <div className="flex items-center gap-1">
          <Button
            aria-label="Предыдущий месяц"
            disabled={!canGoBack(month)}
            onClick={() => {
              setMonth(shiftMonth(month, -1))
            }}
            size="compact"
            type="button"
            variant="ghost"
          >
            <ChevronLeft size={16} />
          </Button>
          <span className="min-w-36 text-center text-sm font-medium">
            {monthLabel(month)}
          </span>
          <Button
            aria-label="Следующий месяц"
            disabled={!canGoForward(month, current)}
            onClick={() => {
              setMonth(shiftMonth(month, 1))
            }}
            size="compact"
            type="button"
            variant="ghost"
          >
            <ChevronRight size={16} />
          </Button>
        </div>
      </div>

      {/* Дни, за которые выгрузка начислений не проходила: итог за них
          неполон, и по цифрам это не видно. */}
      {query.status === 'success' && query.data.daysWithoutAccruals > 0 && (
        <Card tone="warning">
          <StatusPanel
            description={`За ${query.data.daysWithoutAccruals} дн. месяца начисления не загружены — итог с кабинетом не сойдётся.`}
            icon={<TriangleAlert aria-hidden="true" size={20} />}
            role="status"
            title="Месяц загружен не полностью"
            tone="warning"
          />
        </Card>
      )}

      {query.status === 'error' && (
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
                : 'Неизвестная ошибка'
            }
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Не удалось собрать сверку"
            tone="negative"
          />
        </Card>
      )}

      {query.status === 'pending' && (
        <Card>
          <div className="h-48 animate-pulse rounded-md bg-surface-hover" />
        </Card>
      )}

      {query.status === 'success' && currency === null && (
        <Card>
          <StatusPanel
            description="За этот месяц начислений Ozon нет."
            icon={<Scale aria-hidden="true" size={20} />}
            title="Сверять нечего"
            tone="neutral"
          />
        </Card>
      )}

      {query.status === 'success' && currency !== null && (
        <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border-default text-xs text-text-muted">
                <th className="px-4 py-2 text-left font-medium" scope="col">
                  Группа
                </th>
                <th className="px-4 py-2 text-right font-medium" scope="col">
                  Сумма
                </th>
              </tr>
            </thead>
            {query.data.groups.map((group) => {
              const expanded = open.has(group.code)
              return (
                <tbody
                  className="border-b border-border-subtle"
                  key={group.code}
                >
                  <tr>
                    <td className="px-4 py-2">
                      <button
                        aria-expanded={expanded}
                        className="flex cursor-pointer items-center gap-2 text-left font-medium"
                        onClick={() => {
                          toggle(group.code)
                        }}
                        type="button"
                      >
                        {expanded ? (
                          <ChevronDown aria-hidden="true" size={16} />
                        ) : (
                          <ChevronRight aria-hidden="true" size={16} />
                        )}
                        {group.label}
                      </button>
                    </td>
                    <td className="px-4 py-2 text-right font-medium tabular-nums">
                      {formatMinorAmount(group.totalMinor, currency)}
                    </td>
                  </tr>
                  {expanded &&
                    group.items.map((item) => (
                      <Fragment key={`${group.code}-${String(item.feeTypeId)}`}>
                        <tr className="text-text-muted">
                          <td className="py-1 pr-4 pl-12">
                            {item.name}
                            {/* Без группы — тип, которого нет в соответствии
                                кабинета: код нужен, чтобы его дописать. */}
                            {group.code === 'ungrouped'
                              ? ` · код ${String(item.feeTypeId)}`
                              : null}
                          </td>
                          <td className="px-4 py-1 text-right tabular-nums">
                            {formatMinorAmount(item.amountMinor, currency)}
                          </td>
                        </tr>
                        {/* Возврат затраты площадка проводит строкой того же
                            типа с обратным знаком; кабинет показывает его
                            отдельно — и здесь он виден отдельно, чтобы
                            сверять построчно. */}
                        {item.reversedMinor !== 0 && (
                          <>
                            <tr className="text-xs text-text-muted">
                              <td className="py-0.5 pr-4 pl-16">начислено</td>
                              <td className="px-4 py-0.5 text-right tabular-nums">
                                {formatMinorAmount(item.accruedMinor, currency)}
                              </td>
                            </tr>
                            <tr className="text-xs text-text-muted">
                              <td className="py-0.5 pr-4 pl-16">
                                {item.reversedMinor > 0
                                  ? 'возвращено'
                                  : 'удержано'}
                              </td>
                              <td className="px-4 py-0.5 text-right tabular-nums">
                                {formatMinorAmount(
                                  item.reversedMinor,
                                  currency,
                                )}
                              </td>
                            </tr>
                          </>
                        )}
                      </Fragment>
                    ))}
                </tbody>
              )
            })}
            <tfoot>
              <tr className="bg-surface-hover font-semibold">
                <td className="px-4 py-3">Итого к начислению</td>
                <td className="px-4 py-3 text-right tabular-nums">
                  {formatMinorAmount(query.data.totalMinor, currency)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      )}

      {query.status === 'success' && currency !== null && (
        <p className="text-xs text-text-muted">
          Выручку мы делим на продажи и возвраты по знаку. Кабинет часть
          отрицательной выручки (корректировки продаж) показывает в «Продажах»,
          поэтому эти две группы могут расходиться с ним на одну и ту же сумму;
          их сумма и итог к начислению совпадают. «Возвращено» у статьи — все
          строки с обратным знаком: у вознаграждения это «Возврат
          вознаграждения» кабинета вместе с положительными строками
          «Вознаграждения за продажу».
        </p>
      )}
    </div>
  )
}
