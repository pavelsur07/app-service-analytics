import { useEffect, useState } from 'react'
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  CircleX,
  TriangleAlert,
  Wallet,
} from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { PAGE_SIZES, WINDOWS, parsePageSize } from '../lib/tableParams'
import { useUnitEconomics } from '../model/useUnitEconomics'
import { useUnitEconomicsView } from '../model/useUnitEconomicsView'
import {
  UnitEconomicsTable,
  UnitEconomicsTableSkeleton,
} from './UnitEconomicsTable'

/**
 * Юнит-экономика: прибыль там, где задана себестоимость, и маржа там,
 * где не задана.
 *
 * Экран — таблица, а не стопка карточек: его открывают, чтобы сравнить
 * товары между собой, а числа сравниваются глазом только когда стоят
 * в колонках друг под другом.
 *
 * Прибыль показывается только когда цена известна на все проданные дни.
 * Иначе на её месте ссылка «задать себестоимость»: ноль вместо
 * неизвестной закупки завысил бы прибыль ровно на её величину
 * и выглядел бы при этом настоящей цифрой (ADR-013).
 */
export function UnitEconomicsPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()
  const view = useUnitEconomicsView(companyId ?? '')
  const [cabinetOpen, setCabinetOpen] = useState(false)

  const query = useUnitEconomics(companyId ?? '', view.params, {
    enabled: companyId !== undefined,
  })

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

  const nextCursor =
    query.status === 'success' ? (query.data.nextCursor ?? null) : null

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-xl font-semibold">Юнит-экономика</h1>
        <div className="flex items-center gap-1">
          {WINDOWS.map((window) => (
            <Button
              key={window}
              onClick={() => {
                view.setDays(window)
              }}
              size="compact"
              type="button"
              variant={window === view.params.days ? 'primary' : 'secondary'}
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
            description={`Расходы загружены не за весь период: за ${query.data.daysWithoutExpenses} из ${view.params.days} дней их нет, и маржа за эти дни завышена.`}
            icon={<TriangleAlert aria-hidden="true" size={20} />}
            role="status"
            title="Период посчитан не полностью"
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
            title="Не удалось посчитать экономику"
            tone="negative"
          />
        </Card>
      )}

      {/* Не размазываются по товарам намеренно (ADR-012): реклама
          и хранение относятся к кабинету, и доля, происхождение которой
          клиент не проверит, хуже честной отдельной строки. */}
      {query.status === 'success' &&
        query.data.cabinetExpensesTotalMinor !== 0 && (
          <Card>
            <div className="flex flex-col gap-2">
              <button
                aria-expanded={cabinetOpen}
                className="flex cursor-pointer items-center justify-between gap-2 text-left"
                onClick={() => {
                  setCabinetOpen((open) => !open)
                }}
                type="button"
              >
                <span className="flex items-center gap-2 font-medium">
                  {cabinetOpen ? (
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

              <p className="text-sm text-text-muted">
                Реклама, хранение и прочее, что Ozon не относит к конкретному
                товару.
              </p>

              {cabinetOpen && (
                <dl className="flex flex-col gap-1 border-t border-border-subtle pt-2 text-sm">
                  {query.data.cabinetExpenses.map((expense) => (
                    <div
                      className="flex items-center justify-between gap-4"
                      key={expense.feeTypeId}
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

      {/* «Нечего считать» — только про первую страницу. Дальше по списку
          пустой ответ значит другое: данные изменились между двумя
          keyset-запросами, и страница, на которой клиент стоит, опустела.
          Сказать ему там «нет ни продаж, ни расходов» и убрать таблицу
          вместе с кнопкой «Назад» — соврать и запереть. */}
      {query.status === 'success' &&
      query.data.skus.length === 0 &&
      view.params.cursor === null ? (
        <Card>
          <StatusPanel
            description="За выбранный период нет ни продаж, ни расходов по товарам."
            icon={<Wallet aria-hidden="true" size={20} />}
            title="Пока считать нечего"
            tone="neutral"
          />
        </Card>
      ) : null}

      {query.status === 'pending' ||
      (query.status === 'success' &&
        (query.data.skus.length > 0 || view.params.cursor !== null)) ? (
        <div className="overflow-hidden rounded-xl border border-border-default bg-surface-raised shadow-card">
          <div className="flex items-center gap-3 border-b border-border-default px-4 py-3">
            <span className="font-semibold">Юнит-экономика</span>
            {query.status === 'success' ? (
              <span className="text-xs text-text-muted tabular-nums">
                {/* Не «из N»: общего числа строк бэкенд не считает —
                    COUNT(*) на таблицах фактов не выполняется (§5). */}
                {query.data.skus.length} строк на странице
              </span>
            ) : null}
          </div>

          <div className="overflow-x-auto">
            {query.status === 'pending' ? (
              <UnitEconomicsTableSkeleton />
            ) : (
              <UnitEconomicsTable
                companyId={companyId}
                currency={query.data.currency}
                direction={view.params.direction}
                onSort={view.toggleSort}
                skus={query.data.skus}
                sort={view.params.sort}
              />
            )}
          </div>

          <div className="flex flex-wrap items-center gap-3 border-t border-border-default px-4 py-2">
            {/* Нативный select, а не примитив: Select в packages/ui нет
                намеренно — до третьего продуктового повторения
                (docs/patterns.md, «Чего в UI Kit нет и почему»). */}
            <label className="flex items-center gap-1.5 text-xs text-text-muted">
              Строк на странице
              <select
                className="h-7 cursor-pointer rounded-md border border-border-default bg-surface-raised px-2 text-xs font-medium text-text-secondary focus:border-accent-default focus:outline-2 focus:outline-border-focus"
                onChange={(event) => {
                  view.setLimit(parsePageSize(event.target.value))
                }}
                value={view.params.limit}
              >
                {PAGE_SIZES.map((size) => (
                  <option key={size} value={size}>
                    {size}
                  </option>
                ))}
              </select>
            </label>

            {/* Номеров страниц и общего счётчика нет намеренно: страница
                читается keyset-курсором, и «страница 5 из 240» стоила бы
                полного прохода по индексу ради числа, на которое никто
                не смотрит (docs/patterns.md, «Пагинация»). */}
            <div className="ml-auto flex items-center gap-2">
              <Button
                disabled={!view.canGoBack}
                onClick={view.goBack}
                size="compact"
                type="button"
                variant="secondary"
              >
                <ChevronLeft aria-hidden="true" size={16} />
                Назад
              </Button>
              <Button
                disabled={nextCursor === null}
                onClick={() => {
                  if (nextCursor !== null) {
                    view.goNext(nextCursor)
                  }
                }}
                size="compact"
                type="button"
                variant="secondary"
              >
                Дальше
                <ChevronRight aria-hidden="true" size={16} />
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
