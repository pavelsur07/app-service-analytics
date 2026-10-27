import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'

import type { components } from '../../../api/schema'
import { WIDGETS } from '../lib/widgets'
import { WidgetCard } from './WidgetCard'

type Metrics = components['schemas']['DashboardMetricsResponse']

const METRICS: Metrics = {
  ordersAmountMinor: 45_000_000,
  ordersQuantity: 180,
  salesAmountMinor: 30_000_000,
  salesQuantity: 120,
  returnsAmountMinor: 2_000_000,
  returnsQuantity: 8,
  advertisingMinor: 5_400_000,
  costPerOrderMinor: 30_000,
  drrOrdersBps: 1_200,
  costPerSaleMinor: null,
  drrSalesBps: null,
  deliveryLogisticsMinor: 1_000_000,
  deliveryCount: 170,
  returnLogisticsMinor: 50_000,
  returnLogisticsCount: 9,
  buyoutRateBps: 6_350,
  buyoutQuantity: 114,
  buyoutOrderedQuantity: 180,
  buyoutResolutionRateBps: 7_000,
}

const DAYS = [
  {
    date: '2026-10-08',
    ordersAmountMinor: 1,
    salesAmountMinor: 0,
    returnsAmountMinor: 0,
    advertisingMinor: 0,
    deliveryLogisticsMinor: 0,
    returnLogisticsMinor: 0,
  },
  {
    date: '2026-10-09',
    ordersAmountMinor: 3,
    salesAmountMinor: 0,
    returnsAmountMinor: 0,
    advertisingMinor: 0,
    deliveryLogisticsMinor: 0,
    returnLogisticsMinor: 0,
  },
]

function widget(id: string) {
  const spec = WIDGETS.find((item) => item.id === id)
  if (spec === undefined) {
    throw new Error(id)
  }

  return spec
}

function render(id: string, hint: string | null = null): string {
  // Intl разделяет разряды неразрывными пробелами; в ожиданиях — обычные.
  return renderToStaticMarkup(
    <WidgetCard
      changes={{
        advertisingBps: 3_500,
        drrOrdersPointsBps: 200,
        costPerOrderBps: null,
        ordersAmountBps: 1_250,
      }}
      comparison={{
        ...METRICS,
        ordersAmountMinor: 40_000_000,
        advertisingMinor: 4_000_000,
      }}
      comparisonDays={DAYS}
      currency="RUB"
      current={METRICS}
      currentDays={DAYS}
      hint={hint}
      onHide={() => undefined}
      spec={widget(id)}
    />,
  ).replace(/\s/gu, ' ')
}

describe('виджет главной', () => {
  it('значение, изменение, «было» и строки — из ответа бэкенда', () => {
    const html = render('drrOrders')

    expect(html).toContain('Расходы на рекламу')
    expect(html).toContain('54 000 ₽')
    expect(html).toContain('было 40 000 ₽')
    expect(html).toContain('300,00 ₽')
    expect(html).toContain('12%')
    // ДРР вырос на 2 п.п. — это хуже; цена заказа без сравнения.
    expect(html).toContain('▲ +2,0 п.п.')
    expect(html).toContain('— нет данных')
    expect(html).toContain('aria-label="Скрыть виджет: ДРР · от заказов"')
  })

  it('спарклайн с пунктиром сравнения у аддитивных показателей', () => {
    const html = render('orders')

    expect(html).toContain('role="img"')
    expect(html).toContain('stroke-dasharray="3 3"')
    expect(html).toContain('к концу периода показатель вырос')
  })

  it('не рисует недоступный ряд прибыли и сохраняет только сравнение', () => {
    const spec = {
      id: 'profit',
      title: 'Прибыль',
      primary: {
        label: 'Прибыль',
        metric: 'profitMinor',
        format: 'money',
        change: 'profitBps',
        kind: 'relative',
        good: 'up',
        series: 'profitMinor',
      },
      rows: [],
    } as const
    const html = renderToStaticMarkup(
      <WidgetCard
        spec={spec}
        currency="RUB"
        current={{ ...METRICS, profitMinor: null }}
        comparison={{ ...METRICS, profitMinor: 10000 }}
        changes={{ profitBps: null }}
        currentDays={DAYS.map((day) => ({ ...day, profitMinor: null }))}
        comparisonDays={DAYS.map((day) => ({ ...day, profitMinor: 10000 }))}
        hint={null}
        onHide={() => undefined}
      />,
    )
    expect(html.match(/<polyline/g)).toHaveLength(1)
    expect(html).toContain('период сравнения')
    expect(html).not.toContain('к концу периода показатель')
  })

  it('не рисует оба недоступных ряда прибыли', () => {
    const spec = {
      id: 'profit',
      title: 'Прибыль',
      primary: {
        label: 'Прибыль',
        metric: 'profitMinor',
        format: 'money',
        change: 'profitBps',
        kind: 'relative',
        good: 'up',
        series: 'profitMinor',
      },
      rows: [],
    } as const
    const html = renderToStaticMarkup(
      <WidgetCard
        spec={spec}
        currency="RUB"
        current={{ ...METRICS, profitMinor: null }}
        comparison={{ ...METRICS, profitMinor: null }}
        changes={{}}
        currentDays={DAYS.map((day) => ({ ...day, profitMinor: null }))}
        comparisonDays={DAYS.map((day) => ({ ...day, profitMinor: null }))}
        hint={null}
        onHide={() => undefined}
      />,
    )
    expect(html).not.toContain('role="img"')
    expect(html).not.toContain('NaN')
  })

  it('у выкупа спарклайна нет, а подсказка о созревании есть', () => {
    const html = render('buyout', 'Исход известен по 70% заказов')

    expect(html).not.toContain('role="img"')
    expect(html).toContain('63,5%')
    expect(html).toContain('Исход известен по 70% заказов')
  })

  it('отношение без знаменателя — прочерк, а не ноль', () => {
    const html = render('drrSales')

    expect(html).toContain('Цена 1 продажи')
    expect(html).toMatch(/Цена 1 продажи<\/span><span[^>]*><span[^>]*>—</)
  })
})
