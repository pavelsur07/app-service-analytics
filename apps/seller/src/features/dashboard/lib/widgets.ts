// Виджеты главной: что показывает каждый и что в нём «лучше»
// (docs/plan/dashboard-widgets.md). Значения и изменения посчитал
// бэкенд; здесь — только выбор поля и форматирование.

import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'

type Metrics = components['schemas']['DashboardMetricsResponse']
type Changes = components['schemas']['DashboardChangesResponse']
type Day = components['schemas']['DashboardDayResponse']

type MetricKey = keyof Metrics
type ChangeKey = keyof Changes
type SeriesKey = Exclude<keyof Day, 'date'>

/** Целые рубли; копейки — у цены одного заказа или продажи. */
type ValueFormat = 'money' | 'moneyPrecise' | 'quantity' | 'percent'

/**
 * `relative` — изменение суммы или штук, «+12,5%». `points` — разница
 * процентов, «+2,0 п.п.»: ДРР с 10 % на 12 % — не «+20 %».
 */
type ChangeKind = 'relative' | 'points'

/** Какое направление — к лучшему. `neutral` — ни то, ни другое. */
type Polarity = 'up' | 'down' | 'neutral'

export interface MetricSpec {
  label: string
  metric: MetricKey
  format: ValueFormat
  change: ChangeKey
  kind: ChangeKind
  good: Polarity
}

export interface WidgetSpec {
  id: string
  title: string
  primary: MetricSpec & { series: SeriesKey | null }
  rows: readonly MetricSpec[]
}

export const WIDGETS = [
  {
    id: 'orders',
    title: 'Заказы',
    primary: {
      label: 'Сумма заказов',
      metric: 'ordersAmountMinor',
      format: 'money',
      change: 'ordersAmountBps',
      kind: 'relative',
      good: 'up',
      series: 'ordersAmountMinor',
    },
    rows: [
      {
        label: 'Количество',
        metric: 'ordersQuantity',
        format: 'quantity',
        change: 'ordersQuantityBps',
        kind: 'relative',
        good: 'up',
      },
    ],
  },
  {
    id: 'sales',
    title: 'Продажи',
    primary: {
      label: 'Сумма продаж',
      metric: 'salesAmountMinor',
      format: 'money',
      change: 'salesAmountBps',
      kind: 'relative',
      good: 'up',
      series: 'salesAmountMinor',
    },
    rows: [
      {
        label: 'Количество',
        metric: 'salesQuantity',
        format: 'quantity',
        change: 'salesQuantityBps',
        kind: 'relative',
        good: 'up',
      },
    ],
  },
  {
    id: 'profit',
    title: 'Прибыль',
    primary: {
      label: 'Прибыль по начислениям',
      metric: 'profitMinor',
      format: 'money',
      change: 'profitBps',
      kind: 'relative',
      good: 'up',
      series: 'profitMinor',
    },
    rows: [],
  },
  {
    // Спарклайна нет: доля выкупа когортная и не складывается по дням
    // (docs/plan/dashboard-widgets.md, «Не входит»).
    id: 'buyout',
    title: '% выкупа по кабинету',
    primary: {
      label: 'Выкуплено от заказанного, прогноз',
      metric: 'buyoutRateBps',
      format: 'percent',
      change: 'buyoutRatePointsBps',
      kind: 'points',
      good: 'up',
      series: null,
    },
    rows: [
      {
        label: 'Выкуплено, прогноз',
        metric: 'buyoutQuantity',
        format: 'quantity',
        change: 'buyoutQuantityBps',
        kind: 'relative',
        good: 'up',
      },
      {
        label: 'Заказано',
        metric: 'buyoutOrderedQuantity',
        format: 'quantity',
        change: 'buyoutOrderedQuantityBps',
        kind: 'relative',
        good: 'neutral',
      },
    ],
  },
  {
    id: 'returns',
    title: 'Возвраты',
    primary: {
      label: 'Сумма возвратов',
      metric: 'returnsAmountMinor',
      format: 'money',
      change: 'returnsAmountBps',
      kind: 'relative',
      good: 'down',
      series: 'returnsAmountMinor',
    },
    rows: [
      {
        label: 'Количество',
        metric: 'returnsQuantity',
        format: 'quantity',
        change: 'returnsQuantityBps',
        kind: 'relative',
        good: 'down',
      },
    ],
  },
  {
    id: 'drrOrders',
    title: 'ДРР · от заказов',
    primary: {
      label: 'Расходы на рекламу',
      metric: 'advertisingMinor',
      format: 'money',
      change: 'advertisingBps',
      kind: 'relative',
      good: 'neutral',
      series: 'advertisingMinor',
    },
    rows: [
      {
        label: 'Цена 1 заказа',
        metric: 'costPerOrderMinor',
        format: 'moneyPrecise',
        change: 'costPerOrderBps',
        kind: 'relative',
        good: 'down',
      },
      {
        label: 'ДРР от суммы заказов',
        metric: 'drrOrdersBps',
        format: 'percent',
        change: 'drrOrdersPointsBps',
        kind: 'points',
        good: 'down',
      },
    ],
  },
  {
    id: 'drrSales',
    title: 'ДРР · от продаж',
    primary: {
      label: 'Расходы на рекламу',
      metric: 'advertisingMinor',
      format: 'money',
      change: 'advertisingBps',
      kind: 'relative',
      good: 'neutral',
      series: 'advertisingMinor',
    },
    rows: [
      {
        label: 'Цена 1 продажи',
        metric: 'costPerSaleMinor',
        format: 'moneyPrecise',
        change: 'costPerSaleBps',
        kind: 'relative',
        good: 'down',
      },
      {
        label: 'ДРР от суммы продаж',
        metric: 'drrSalesBps',
        format: 'percent',
        change: 'drrSalesPointsBps',
        kind: 'points',
        good: 'down',
      },
    ],
  },
  {
    id: 'logDelivery',
    title: 'Логистика · доставка до клиента',
    primary: {
      label: 'Стоимость логистики',
      metric: 'deliveryLogisticsMinor',
      format: 'money',
      change: 'deliveryLogisticsBps',
      kind: 'relative',
      good: 'down',
      series: 'deliveryLogisticsMinor',
    },
    rows: [
      {
        label: 'Количество доставок',
        metric: 'deliveryCount',
        format: 'quantity',
        change: 'deliveryCountBps',
        kind: 'relative',
        good: 'neutral',
      },
    ],
  },
  {
    id: 'logReturn',
    title: 'Логистика · возвраты от клиента',
    primary: {
      label: 'Стоимость логистики',
      metric: 'returnLogisticsMinor',
      format: 'money',
      change: 'returnLogisticsBps',
      kind: 'relative',
      good: 'down',
      series: 'returnLogisticsMinor',
    },
    rows: [
      {
        label: 'Количество возвратов',
        metric: 'returnLogisticsCount',
        format: 'quantity',
        change: 'returnLogisticsCountBps',
        kind: 'relative',
        good: 'neutral',
      },
    ],
  },
] as const satisfies readonly WidgetSpec[]

export type WidgetId = (typeof WIDGETS)[number]['id']

const WIDGET_IDS: readonly WidgetId[] = WIDGETS.map((widget) => widget.id)

export function isWidgetId(value: unknown): value is WidgetId {
  return WIDGET_IDS.some((id) => id === value)
}

const QUANTITY = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 })

/**
 * Значение показателя для показа. NULL — «—»: считать не из чего
 * (ноль в знаменателе), и ноль здесь был бы неправдой.
 */
export function formatValue(
  value: number | null | undefined,
  format: ValueFormat,
  currency: string,
): string {
  if (value === null || value === undefined) {
    return '—'
  }

  switch (format) {
    case 'money':
    case 'moneyPrecise':
      return formatMinorAmount(value, currency, {
        wholeUnits: format === 'money',
      })
    case 'quantity':
      return `${QUANTITY.format(value)} шт.`
    case 'percent':
      return formatBasisPoints(value)
  }
}

export type ChangeTone = 'positive' | 'negative' | 'neutral'

export interface ChangeView {
  text: string
  title: string
  tone: ChangeTone
}

/** Базисные пункты в «12,5» — одна десятая, половина от нуля, без float. */
function tenths(bps: number): string {
  const absolute = Math.abs(Math.trunc(bps))
  const rounded = Math.floor((absolute + 5) / 10)

  return `${String(Math.floor(rounded / 10))},${String(rounded % 10)}`
}

/**
 * Изменение к периоду сравнения: стрелка, знак и тон по смыслу
 * показателя. Цвет не единственный носитель: стрелка и слово
 * в подсказке говорят то же самое (docs/patterns.md, «Данные и статусы»).
 */
export function changeView(
  bps: number | null | undefined,
  kind: ChangeKind,
  good: Polarity,
): ChangeView {
  const unit = kind === 'points' ? ' п.п.' : '%'
  if (bps === null || bps === undefined) {
    return {
      text: '— нет данных',
      title: 'В периоде сравнения нет данных для сравнения',
      tone: 'neutral',
    }
  }

  const body = `${tenths(bps)}${unit}`
  if (Math.abs(bps) < 5) {
    return {
      text: `— 0,0${unit}`,
      title: 'Без изменений к периоду сравнения',
      tone: 'neutral',
    }
  }

  const up = bps > 0
  const better = good === 'neutral' ? null : (good === 'up') === up
  const verdict = better === null ? 'нейтрально' : better ? 'лучше' : 'хуже'

  return {
    text: `${up ? '▲ +' : '▼ −'}${body}`,
    title: `${up ? 'Выросло' : 'Снизилось'} на ${body} к периоду сравнения — ${verdict}`,
    tone: better === null ? 'neutral' : better ? 'positive' : 'negative',
  }
}
