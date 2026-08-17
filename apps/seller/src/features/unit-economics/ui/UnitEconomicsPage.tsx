import { useEffect, useState } from 'react'
import {
  ChevronDown,
  ChevronRight,
  CircleX,
  TriangleAlert,
  Wallet,
} from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { isLoss, shareOfRevenue } from '../lib/margin'
import { useUnitEconomics } from '../model/useUnitEconomics'

const WINDOWS = [7, 30, 90] as const

/**
 * Юнит-экономика: прибыль там, где задана себестоимость, и маржа там,
 * где не задана.
 *
 * Прибыль показывается только когда цена известна на все проданные дни.
 * Иначе на месте числа стоит ссылка «задать цену»: ноль вместо
 * неизвестной закупки завысил бы прибыль ровно на её величину
 * и выглядел бы при этом настоящей цифрой (ADR-013).
 */
export function UnitEconomicsPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const [days, setDays] = useState<number>(30)
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})
  const cursor = cursorStack[cursorStack.length - 1] ?? null

  const query = useUnitEconomics(companyId ?? '', days, cursor, {
    enabled: companyId !== undefined,
  })

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  if (companyId === undefined) {
    return (
      <div className="p-6">
        <Card tone="negative">
          <StatusPanel
            description="В адресе не указан companyId."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Некорректный адрес"
            tone="negative"
          />
        </Card>
      </div>
    )
  }

  const toggle = (key: string) => {
    setExpanded((current) => ({ ...current, [key]: !current[key] }))
  }

  return (
    <div className="p-6">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h1 className="text-xl font-semibold">Юнит-экономика</h1>
          <div className="flex items-center gap-1">
            {WINDOWS.map((window) => (
              <Button
                key={window}
                type="button"
                size="compact"
                variant={window === days ? 'primary' : 'secondary'}
                onClick={() => {
                  setDays(window)
                  // Курсор принадлежит окну: оставить его при смене
                  // периода значило бы открыть вторую страницу другого
                  // отчёта.
                  setCursorStack([null])
                }}
              >
                {window} дней
              </Button>
            ))}
          </div>
        </div>

        {/* Прямо на экране, а не в справке: расход начисляется позже
            продажи, иногда на недели, и за короткое окно картина неполна
            по природе источника, а не из-за сбоя (ADR-012). */}
        <p className="text-sm text-text-muted">
          Прибыль — выручка за вычетом расходов площадки и себестоимости
          проданного. Там, где цена закупки не задана, показана маржа: сколько
          осталось от продажи после расходов площадки. Расходы приходят от Ozon
          позже продажи, поэтому за последние дни картина неполная.
        </p>

        {/* Подпись выше говорит про хвост последних дней — это свойство
            источника. Здесь другое: за эти дни выгрузка расходов не
            проходила вовсе, и маржа завышена на всю логистику и возвраты.
            Молчать об этом нельзя — по цифрам такой день неотличим
            от честного. */}
        {query.status === 'success' && query.data.daysWithoutExpenses > 0 && (
          <Card tone="warning">
            <StatusPanel
              description={`Расходы загружены не за весь период: за ${query.data.daysWithoutExpenses} из ${days} дней их нет, и маржа за эти дни завышена.`}
              icon={<TriangleAlert aria-hidden="true" size={20} />}
              role="status"
              title="Период посчитан не полностью"
              tone="warning"
            />
          </Card>
        )}

        {query.status === 'pending' && (
          <Card>
            <div className="h-32 animate-pulse rounded bg-border-subtle" />
          </Card>
        )}

        {query.status === 'error' && (
          <Card tone="negative">
            <StatusPanel
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  onClick={() => {
                    void query.refetch()
                  }}
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
              title="Не удалось посчитать экономику"
              tone="negative"
            />
          </Card>
        )}

        {query.status === 'success' && (
          <>
            {query.data.cabinetExpensesTotalMinor !== 0 && (
              <Card>
                <div className="flex flex-col gap-2">
                  <button
                    type="button"
                    className="flex items-center justify-between gap-2 text-left"
                    onClick={() => {
                      toggle('cabinet')
                    }}
                    aria-expanded={expanded.cabinet === true}
                  >
                    <span className="flex items-center gap-2 font-medium">
                      {expanded.cabinet === true ? (
                        <ChevronDown aria-hidden="true" size={16} />
                      ) : (
                        <ChevronRight aria-hidden="true" size={16} />
                      )}
                      <Wallet aria-hidden="true" size={16} />
                      Расходы кабинета
                    </span>
                    <span className="font-medium">
                      {formatMinorAmount(
                        query.data.cabinetExpensesTotalMinor,
                        query.data.currency,
                      )}
                    </span>
                  </button>

                  {/* Не размазываются по товарам намеренно (ADR-012):
                      реклама и хранение относятся к кабинету, и доля,
                      происхождение которой клиент не проверит, хуже
                      честной отдельной строки. */}
                  <p className="text-sm text-text-muted">
                    Реклама, хранение и прочее, что Ozon не относит к
                    конкретному товару.
                  </p>

                  {expanded.cabinet === true && (
                    <dl className="flex flex-col gap-1 border-t border-border-subtle pt-2 text-sm">
                      {query.data.cabinetExpenses.map((expense) => (
                        <div
                          key={expense.feeTypeId}
                          className="flex items-center justify-between gap-4"
                        >
                          <dt className="text-text-muted">{expense.name}</dt>
                          <dd>
                            {formatMinorAmount(
                              expense.amountMinor,
                              query.data.currency,
                            )}
                          </dd>
                        </div>
                      ))}
                    </dl>
                  )}
                </div>
              </Card>
            )}

            {query.data.skus.length === 0 && (
              <Card>
                <StatusPanel
                  description="За выбранный период нет ни продаж, ни расходов по товарам."
                  icon={<Wallet aria-hidden="true" size={20} />}
                  title="Пока считать нечего"
                  tone="neutral"
                />
              </Card>
            )}

            {query.data.skus.map((sku) => {
              // Сумма удержаний приходит с бэкенда: арифметика над
              // денежными величинами в компонентах запрещена (§10).
              const share = shareOfRevenue(
                sku.deductionsTotalMinor,
                sku.revenueMinor,
              )

              return (
                <Card key={sku.marketplaceSku}>
                  <div className="flex flex-col gap-2">
                    <button
                      type="button"
                      className="flex items-center justify-between gap-2 text-left"
                      onClick={() => {
                        toggle(sku.marketplaceSku)
                      }}
                      aria-expanded={expanded[sku.marketplaceSku] === true}
                    >
                      <span className="flex items-center gap-2 font-medium">
                        {expanded[sku.marketplaceSku] === true ? (
                          <ChevronDown aria-hidden="true" size={16} />
                        ) : (
                          <ChevronRight aria-hidden="true" size={16} />
                        )}
                        {sku.marketplaceSku}
                      </span>
                      <span className="flex items-center gap-2">
                        {/* Прибыль, если известна; иначе маржа и прямое
                            указание, чего не хватает. Молча показать
                            маржу под видом прибыли — ровно та ошибка,
                            от которой ADR-013 защищает. */}
                        {(sku.profitMinor ?? null) === null ? (
                          <>
                            <Badge tone="warning">Нет себестоимости</Badge>
                            <Badge
                              tone={
                                isLoss(sku.marginMinor)
                                  ? 'negative'
                                  : 'positive'
                              }
                            >
                              {formatMinorAmount(
                                sku.marginMinor,
                                query.data.currency,
                              )}
                            </Badge>
                          </>
                        ) : (
                          <Badge
                            tone={
                              isLoss(sku.profitMinor ?? 0)
                                ? 'negative'
                                : 'positive'
                            }
                          >
                            {formatMinorAmount(
                              sku.profitMinor ?? 0,
                              query.data.currency,
                            )}
                          </Badge>
                        )}
                      </span>
                    </button>

                    <dl className="flex flex-wrap gap-x-8 gap-y-1 text-sm">
                      <div>
                        <dt className="text-text-muted">Доставлено</dt>
                        <dd>{sku.deliveredQuantity} шт</dd>
                      </div>
                      <div>
                        <dt className="text-text-muted">Выручка</dt>
                        <dd>
                          {formatMinorAmount(
                            sku.revenueMinor,
                            query.data.currency,
                          )}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-text-muted">Комиссия</dt>
                        <dd>
                          {formatMinorAmount(
                            sku.commissionMinor,
                            query.data.currency,
                          )}
                        </dd>
                      </div>
                      <div>
                        <dt className="text-text-muted">Расходы площадки</dt>
                        <dd>
                          {formatMinorAmount(
                            sku.expensesTotalMinor,
                            query.data.currency,
                          )}
                        </dd>
                      </div>
                      {/* Разложение по компонентам, а не одно итоговое
                          число: клиент сверяет цифру с отчётом площадки,
                          и «прибыль 3 000» без слагаемых нечем проверить. */}
                      <div>
                        <dt className="text-text-muted">Себестоимость</dt>
                        <dd>
                          {formatMinorAmount(
                            sku.costTotalMinor,
                            query.data.currency,
                          )}
                          {sku.quantityWithoutCost > 0 && (
                            <>
                              {' '}
                              <Link
                                className="text-accent-default underline"
                                to={`/companies/${companyId}/costs`}
                              >
                                нет цены у {sku.quantityWithoutCost} шт
                              </Link>
                            </>
                          )}
                        </dd>
                      </div>
                      {(sku.costCorrectedAt ?? null) !== null && (
                        <div>
                          {/* Прибыль считается при чтении, поэтому отчёт
                              за прошлый месяц меняется под руками.
                              Это цена решения, и она оплачивается
                              честностью — цифра не меняется молча. */}
                          <dt className="text-text-muted">Цена правилась</dt>
                          <dd>{(sku.costCorrectedAt ?? '').slice(0, 10)}</dd>
                        </div>
                      )}
                      <div>
                        <dt className="text-text-muted">Маржа</dt>
                        <dd>
                          {formatMinorAmount(
                            sku.marginMinor,
                            query.data.currency,
                          )}
                        </dd>
                      </div>
                      {share !== null && (
                        <div>
                          <dt className="text-text-muted">
                            Съедает от выручки
                          </dt>
                          <dd>{Math.round(share * 100)}%</dd>
                        </div>
                      )}
                    </dl>

                    {expanded[sku.marketplaceSku] === true && (
                      <dl className="flex flex-col gap-1 border-t border-border-subtle pt-2 text-sm">
                        {sku.expenses.length === 0 && (
                          <p className="text-text-muted">
                            Расходов по этому товару за период не начислено.
                          </p>
                        )}
                        {sku.expenses.map((expense) => (
                          <div
                            key={expense.feeTypeId}
                            className="flex items-center justify-between gap-4"
                          >
                            <dt className="text-text-muted">{expense.name}</dt>
                            <dd>
                              {formatMinorAmount(
                                expense.amountMinor,
                                query.data.currency,
                              )}
                            </dd>
                          </div>
                        ))}
                      </dl>
                    )}
                  </div>
                </Card>
              )
            })}

            {((query.data.nextCursor ?? null) !== null ||
              cursorStack.length > 1) && (
              <div className="flex items-center justify-end gap-2">
                {/* Не тихая обрезка: страница ограничена, и клиент видит,
                    что за ней есть ещё (§5). */}
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={cursorStack.length <= 1}
                  onClick={() => {
                    setCursorStack((stack: (string | null)[]) =>
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
                  disabled={(query.data.nextCursor ?? null) === null}
                  onClick={() => {
                    // Схема допускает и null, и отсутствие поля —
                    // сводим к одному значению, иначе «Дальше» уводило
                    // бы на страницу с курсором undefined.
                    const next = query.data.nextCursor ?? null
                    if (next !== null) {
                      setCursorStack((stack: (string | null)[]) => [
                        ...stack,
                        next,
                      ])
                    }
                  }}
                >
                  Дальше
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
