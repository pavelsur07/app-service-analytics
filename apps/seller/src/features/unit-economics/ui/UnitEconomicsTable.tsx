import {
  ArrowDown,
  ArrowUp,
  ChevronDown,
  ChevronRight,
  TriangleAlert,
} from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { marginSign, marginTone, shareOfRevenue } from '../lib/margin'
import type { SortDirection, SortKey } from '../lib/tableParams'

type UnitEconomicsSku = components['schemas']['UnitEconomicsSkuResponse']

interface Column {
  label: string
  width: string
  sort?: SortKey
}

// Ширины сняты с макета на ближайшее кратное 4px: тогда и смещения
// закреплённых колонок становятся значениями шкалы Tailwind, а не
// произвольными — а произвольные запрещены линтером.
const COLUMNS: Column[] = [
  { label: 'SKU', width: 'w-28' },
  { label: 'Название', width: 'w-76' },
  { label: 'Артик.', width: 'w-30' },
  { label: 'Доставлено, шт.', width: 'w-32', sort: 'delivered' },
  { label: 'Выручка', width: 'w-32', sort: 'revenue' },
  { label: 'Комиссия', width: 'w-30', sort: 'commission' },
  { label: 'Расходы площадки', width: 'w-44', sort: 'expenses' },
  { label: 'Себестоимость', width: 'w-32', sort: 'cost' },
  { label: 'Маржа', width: 'w-38', sort: 'margin' },
]

// Сумма ширин колонок: 112+304+120+128+128+120+176+128+152.
// table-fixed берёт ширины из шапки, min-w не даёт им схлопнуться
// на узком окне — вместо этого появляется горизонтальная прокрутка,
// а первые две колонки остаются на месте.
const TABLE_WIDTH = 'min-w-342'

const HEAD_CELL = 'border-b border-border-default px-3 py-2'
const BODY_CELL = 'border-b border-border-subtle px-3 py-1.5 whitespace-nowrap'

// Закреплённые ячейки уезжают поверх соседних и обязаны нести
// собственный непрозрачный фон: у прозрачной сквозь неё видно то,
// что должно было проехать под ней.
const STICKY_SKU = 'sticky left-0 z-10'
const STICKY_NAME = 'sticky left-28 z-10 border-r border-border-default'

function SortableHeader({
  column,
  sort,
  direction,
  onSort,
}: {
  column: Column
  sort: SortKey
  direction: SortDirection
  onSort: (key: SortKey) => void
}) {
  const key = column.sort

  if (key === undefined) {
    return null
  }

  const active = key === sort
  const Arrow = direction === 'asc' ? ArrowUp : ArrowDown

  return (
    <button
      className="inline-flex w-full cursor-pointer items-center justify-end gap-1 hover:text-text-primary"
      onClick={() => {
        onSort(key)
      }}
      type="button"
    >
      {column.label}
      {active ? (
        <Arrow aria-hidden="true" className="text-accent-default" size={14} />
      ) : null}
    </button>
  )
}

function Head({
  sort,
  direction,
  onSort,
}: {
  sort: SortKey
  direction: SortDirection
  onSort: (key: SortKey) => void
}) {
  return (
    <thead>
      <tr className="bg-surface-sunken text-xs font-semibold text-text-secondary">
        {COLUMNS.map((column, index) => {
          const sticky =
            index === 0 ? STICKY_SKU : index === 1 ? STICKY_NAME : ''
          const align = column.sort === undefined ? '' : 'text-right'
          // aria-sort ставится на ячейку, а не на кнопку: иначе
          // программа чтения с экрана не скажет, по чему отсортировано.
          const ariaSort =
            column.sort === undefined || column.sort !== sort
              ? undefined
              : direction === 'asc'
                ? 'ascending'
                : 'descending'

          return (
            <th
              aria-sort={ariaSort}
              className={`${HEAD_CELL} ${column.width} ${align} ${sticky} ${sticky === '' ? '' : 'bg-surface-sunken'}`}
              key={column.label}
              scope="col"
            >
              {column.sort === undefined ? (
                column.label
              ) : (
                <SortableHeader
                  column={column}
                  direction={direction}
                  onSort={onSort}
                  sort={sort}
                />
              )}
            </th>
          )
        })}
      </tr>
    </thead>
  )
}

/**
 * Раскрытая строка: то, для чего в таблице нет колонки, но что экран
 * обязан назвать.
 *
 * Прибыль здесь, а не в шапке строки: колонки под неё в макете нет,
 * а удалять её нельзя — это главный вопрос, с которым сюда приходят.
 * Когда цена задана не на все проданные дни, вместо числа стоит прямое
 * указание, чего не хватает: ноль вместо неизвестной закупки завысил бы
 * прибыль ровно на её величину и выглядел бы настоящей цифрой (ADR-013).
 */
function Breakdown({
  companyId,
  currency,
  sku,
}: {
  companyId: string
  currency: string
  sku: UnitEconomicsSku
}) {
  const share = shareOfRevenue(sku.deductionsTotalMinor, sku.revenueMinor)
  const profit = sku.profitMinor ?? null

  return (
    <tr>
      <td
        className="border-b border-border-subtle px-3 pb-3"
        colSpan={COLUMNS.length}
      >
        <div className="flex flex-col gap-2 rounded-lg border border-border-default bg-surface-hover p-3">
          <dl className="flex flex-wrap gap-x-8 gap-y-1 text-sm">
            <div>
              <dt className="text-xs text-text-muted">Прибыль</dt>
              <dd>
                {profit === null ? (
                  <Link
                    className="text-accent-default underline"
                    to={`/companies/${companyId}/costs`}
                  >
                    задать себестоимость
                  </Link>
                ) : (
                  formatMinorAmount(profit, currency)
                )}
              </dd>
            </div>
            <div>
              <dt className="text-xs text-text-muted">Заказано, шт.</dt>
              <dd>{sku.orderedQuantity}</dd>
            </div>
            {share === null ? null : (
              <div>
                <dt className="text-xs text-text-muted">Съедает от выручки</dt>
                <dd>{Math.round(share * 100)}%</dd>
              </div>
            )}
            {sku.costCorrectedAt === null ||
            sku.costCorrectedAt === undefined ? null : (
              <div>
                {/* Прибыль считается при чтении, поэтому отчёт за прошлый
                    месяц меняется под руками. Это цена решения, и она
                    оплачивается честностью — цифра не меняется молча. */}
                <dt className="text-xs text-text-muted">Цена правилась</dt>
                <dd>{sku.costCorrectedAt.slice(0, 10)}</dd>
              </div>
            )}
          </dl>

          <dl className="flex flex-col gap-1 border-t border-border-subtle pt-2 text-sm">
            <div className="text-xs font-semibold text-text-muted">
              Расходы площадки
            </div>
            {sku.expenses.length === 0 ? (
              <p className="text-text-muted">
                Расходов по этому товару за период не начислено.
              </p>
            ) : (
              sku.expenses.map((expense) => (
                <div
                  className="flex items-center justify-between gap-4"
                  key={expense.feeTypeId}
                >
                  <dt className="text-text-muted">{expense.name}</dt>
                  <dd>{formatMinorAmount(expense.amountMinor, currency)}</dd>
                </div>
              ))
            )}
          </dl>
        </div>
      </td>
    </tr>
  )
}

function Row({
  companyId,
  currency,
  sku,
  striped,
  expanded,
  onToggle,
}: {
  companyId: string
  currency: string
  sku: UnitEconomicsSku
  striped: boolean
  expanded: boolean
  onToggle: () => void
}) {
  const zebra = striped ? 'bg-surface-hover' : 'bg-surface-raised'
  const tone = marginTone(sku.marginMinor, sku.revenueMinor)

  return (
    <>
      <tr className={zebra}>
        <td
          className={`${BODY_CELL} ${STICKY_SKU} ${zebra} text-text-secondary`}
        >
          {sku.marketplaceSku}
        </td>
        <td className={`${BODY_CELL} ${STICKY_NAME} ${zebra} font-medium`}>
          {/* Карточки может ещё не быть: артикул площадки встречается
              в фактах раньше, чем каталог подтянется. Пустая ячейка
              выглядела бы потерей данных, поэтому её занимает артикул. */}
          {sku.name ?? sku.marketplaceSku}
        </td>
        <td className={`${BODY_CELL} text-text-secondary`}>
          {sku.offerId ?? '—'}
        </td>
        <td className={`${BODY_CELL} text-right`}>{sku.deliveredQuantity}</td>
        <td className={`${BODY_CELL} text-right`}>
          {formatMinorAmount(sku.revenueMinor, currency)}
        </td>
        <td className={`${BODY_CELL} text-right text-text-secondary`}>
          {formatMinorAmount(sku.commissionMinor, currency)}
        </td>
        <td className={`${BODY_CELL} text-right text-text-secondary`}>
          <span className="inline-flex items-center justify-end gap-1.5">
            {formatMinorAmount(sku.expensesTotalMinor, currency)}
            <button
              aria-expanded={expanded}
              aria-label={`${expanded ? 'Свернуть' : 'Развернуть'} расходы по товару ${sku.marketplaceSku}`}
              className="grid size-5 flex-none cursor-pointer place-items-center rounded border border-border-default text-text-disabled hover:bg-surface-hover"
              onClick={onToggle}
              type="button"
            >
              {expanded ? (
                <ChevronDown aria-hidden="true" size={12} />
              ) : (
                <ChevronRight aria-hidden="true" size={12} />
              )}
            </button>
          </span>
        </td>
        <td
          className={`${BODY_CELL} text-right whitespace-nowrap text-text-secondary`}
        >
          <span className="inline-flex items-center justify-end gap-1.5">
            {formatMinorAmount(sku.costTotalMinor, currency)}
            {/* Значком, а не словами: подписью «нет цены» строка не влезала
                в колонку и переносилась на вторую, а продукт про плотность
                данных — на экране таблица, а не статья. Сигнал при этом
                остаётся сигналом: это ссылка на ввод себестоимости
                с доступным именем, и полностью то же сказано словами
                в раскрытой строке (ADR-013). */}
            {sku.quantityWithoutCost > 0 ? (
              <Link
                aria-label={`Нет цены у ${sku.quantityWithoutCost} шт, задать себестоимость`}
                className="flex-none text-warning-icon"
                title={`Нет цены у ${sku.quantityWithoutCost} шт`}
                to={`/companies/${companyId}/costs`}
              >
                <TriangleAlert aria-hidden="true" size={14} />
              </Link>
            ) : null}
          </span>
        </td>
        <td className={`${BODY_CELL} text-right`}>
          {/* Знак обязателен: статус читается без цвета — дальтонизм
              и чёрно-белая печать отчёта для бухгалтера. */}
          <Badge size="compact" tone={tone}>
            {marginSign(tone)} {formatMinorAmount(sku.marginMinor, currency)}
          </Badge>
        </td>
      </tr>
      {expanded ? (
        <Breakdown companyId={companyId} currency={currency} sku={sku} />
      ) : null}
    </>
  )
}

export function UnitEconomicsTableSkeleton() {
  return (
    <table
      aria-busy="true"
      className={`${TABLE_WIDTH} w-full table-fixed text-left`}
    >
      <caption className="sr-only">Загрузка юнит-экономики</caption>
      <thead>
        <tr className="bg-surface-sunken text-xs font-semibold text-text-secondary">
          {COLUMNS.map((column) => (
            <th
              className={`${HEAD_CELL} ${column.width}`}
              key={column.label}
              scope="col"
            >
              {column.label}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {[0, 1, 2, 3, 4].map((row) => (
          <tr key={row}>
            {COLUMNS.map((column, index) => (
              <td className={BODY_CELL} key={`${row}-${column.label}`}>
                <span
                  className={`block h-3 animate-shimmer rounded bg-border-subtle ${index >= 3 ? 'ml-auto w-16' : 'w-20'}`}
                />
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

// Обычная <table>, не @tanstack/react-table: сортировкой управляет
// сервер, клик по заголовку пишет параметр в адрес и ничего не сортирует
// на месте — headless-модели здесь нечем управлять. Понадобится
// с первым взаимодействием со столбцами на клиенте, не раньше.
export function UnitEconomicsTable({
  companyId,
  currency,
  skus,
  sort,
  direction,
  onSort,
}: {
  companyId: string
  currency: string
  skus: UnitEconomicsSku[]
  sort: SortKey
  direction: SortDirection
  onSort: (key: SortKey) => void
}) {
  const [expanded, setExpanded] = useState<Record<string, boolean>>({})

  return (
    <table className={`${TABLE_WIDTH} w-full table-fixed text-left`}>
      <Head direction={direction} onSort={onSort} sort={sort} />
      <tbody>
        {skus.map((sku, index) => (
          <Row
            companyId={companyId}
            currency={currency}
            expanded={expanded[sku.marketplaceSku] === true}
            key={sku.marketplaceSku}
            onToggle={() => {
              setExpanded((current) => ({
                ...current,
                [sku.marketplaceSku]: current[sku.marketplaceSku] !== true,
              }))
            }}
            sku={sku}
            striped={index % 2 === 1}
          />
        ))}
      </tbody>
    </table>
  )
}
