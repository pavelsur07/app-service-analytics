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
import { marginBadge, shareOfRevenue } from '../lib/margin'
import { thumbnailUrl } from '../lib/photo'
import type { SortDirection, SortKey } from '../lib/tableParams'

type UnitEconomicsSku = components['schemas']['UnitEconomicsSkuResponse']

interface Column {
  label: string
  width: string
  sort?: SortKey
  /**
   * Смещение закреплённой колонки. Живёт рядом с шириной намеренно:
   * раньше оно выбиралось позиционным тернарником по индексу, и вставка
   * колонки в начало ломала именно его. Здесь рассогласовать ширину
   * и смещение нельзя, не заметив.
   */
  sticky?: string
}

// Закреплённые ячейки уезжают поверх соседних и обязаны нести
// собственный непрозрачный фон: у прозрачной сквозь неё видно то,
// что должно было проехать под ней.
const STICKY_PHOTO = 'sticky left-0 z-10'
const STICKY_SKU = 'sticky left-14 z-10'
const STICKY_NAME = 'sticky left-42 z-10 border-r border-border-default'

// Ширины сняты с макета на ближайшее кратное 4px: тогда и смещения
// закреплённых колонок становятся значениями шкалы Tailwind, а не
// произвольными — а произвольные запрещены линтером.
const COLUMNS: Column[] = [
  { label: 'Фото', width: 'w-14', sticky: STICKY_PHOTO },
  { label: 'SKU', width: 'w-28', sticky: STICKY_SKU },
  { label: 'Название', width: 'w-76', sticky: STICKY_NAME },
  // 176px, а не 120: в 120 не влезал ни один артикул из 62 в снятой
  // фикстуре, а обрезка съедает хвост — именно тот, что различает
  // варианты одного товара, «черный-M» против «черный-L».
  { label: 'Артик.', width: 'w-44' },
  { label: 'Доставлено, шт.', width: 'w-32', sort: 'delivered' },
  { label: 'Выручка', width: 'w-32', sort: 'revenue' },
  { label: 'Комиссия', width: 'w-30', sort: 'commission' },
  { label: 'Расходы площадки', width: 'w-44', sort: 'expenses' },
  { label: 'Себестоимость', width: 'w-32', sort: 'cost' },
  { label: 'Маржа', width: 'w-38', sort: 'margin' },
]

// Сумма ширин колонок: 56+112+304+176+128+128+120+176+128+152.
// table-fixed берёт ширины из шапки, min-w не даёт им схлопнуться
// на узком окне — вместо этого появляется горизонтальная прокрутка,
// а первые три колонки остаются на месте.
const TABLE_WIDTH = 'min-w-370'

const HEAD_CELL = 'border-b border-border-default px-3 py-2'
const BODY_CELL = 'border-b border-border-subtle px-3 py-1.5 whitespace-nowrap'

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
        {COLUMNS.map((column) => {
          const sticky = column.sticky ?? ''
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

/**
 * Превью товара 32×32.
 *
 * Обработчика ошибки нет намеренно: при пустом alt браузеру нечего
 * показать вместо не загрузившейся картинки, а фон border-subtle
 * на самом img оставляет ровно тот же серый квадрат, что и у товара
 * без фото. Загрузка и отказ сходятся в одно состояние без единой
 * строки кода — а обработчик был бы единственной непокрытой веткой:
 * компонентных тестов у приложения нет.
 *
 * Кликом ничего не открывается, поэтому ни cursor-pointer, ни подсказки
 * «Просмотреть фото» из макета здесь нет: обещать действие, которого
 * не будет, хуже, чем не обещать.
 */
function Thumb({ url }: { url: string | null | undefined }) {
  const src = thumbnailUrl(url)

  if (src === null) {
    return <span className="block size-8 rounded bg-border-subtle" />
  }

  return (
    <img
      // Пустой alt, а не aria-hidden: у изображения это тот же смысл —
      // декорация. Имя и артикул стоят в соседних ячейках той же строки,
      // и повторять их голосом на каждой из 25 строк незачем.
      alt=""
      className="size-8 rounded bg-border-subtle object-cover"
      decoding="async"
      // Размеры атрибутами, а не только классом: без них строка
      // подпрыгивает в момент загрузки.
      height={32}
      loading="lazy"
      src={src}
      width={32}
    />
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
  const margin = marginBadge(sku.marginMinor, sku.revenueMinor)

  return (
    <>
      <tr className={zebra}>
        <td className={`${BODY_CELL} ${STICKY_PHOTO} ${zebra}`}>
          <Thumb url={sku.photoUrl} />
        </td>
        <td
          className={`${BODY_CELL} ${STICKY_SKU} ${zebra} text-text-secondary`}
        >
          {sku.marketplaceSku}
        </td>
        {/* truncate, а не один whitespace-nowrap: без overflow-hidden
            длинное название рисовалось поверх соседних колонок, а так как
            колонка закреплённая — и поверх прокручивающегося содержимого.
            Полное значение отдаёт нативный title: компонента подсказки
            в packages/ui нет намеренно, и макет для той же цели
            пользуется тем же атрибутом. */}
        <td
          className={`${BODY_CELL} ${STICKY_NAME} ${zebra} truncate font-medium`}
          title={sku.name ?? undefined}
        >
          {/* Карточки может ещё не быть: артикул площадки встречается
              в фактах раньше, чем каталог подтянется. Пустая ячейка
              выглядела бы потерей данных, поэтому её занимает артикул. */}
          {sku.name ?? sku.marketplaceSku}
        </td>
        <td
          className={`${BODY_CELL} truncate text-text-secondary`}
          title={sku.offerId ?? undefined}
        >
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
          <Badge size="compact" tone={margin.tone}>
            {margin.sign} {formatMinorAmount(margin.magnitudeMinor, currency)}
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
                {/* Первая ячейка — квадрат под превью, а не полоса:
                    полоса в 56 пикселях вылезет, а строка скелета
                    окажется ниже загруженной, и страница дёрнется
                    в момент подстановки данных. */}
                <span
                  className={
                    index === 0
                      ? 'block size-8 animate-shimmer rounded bg-border-subtle'
                      : `block h-3 animate-shimmer rounded bg-border-subtle ${index >= 4 ? 'ml-auto w-16' : 'w-20'}`
                  }
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
        {/* Пустая страница не на первой позиции: данные изменились между
            двумя keyset-запросами. Пустое тело таблицы выглядело бы
            поломкой, поэтому случай назван словами, а «Назад» остаётся
            под таблицей рабочим. */}
        {skus.length === 0 ? (
          <tr>
            <td
              className="px-3 py-6 text-center text-text-muted"
              colSpan={COLUMNS.length}
            >
              На этой странице товаров не осталось — список изменился, пока вы
              его листали. Вернитесь назад.
            </td>
          </tr>
        ) : null}
      </tbody>
    </table>
  )
}
