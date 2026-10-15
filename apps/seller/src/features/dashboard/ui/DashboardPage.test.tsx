import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, it, vi } from 'vitest'

import type { components } from '../../../api/schema'
import { authQueryKey } from '../../../shared/lib/authQueryKey'
import { comparisonFor, rangeFor, todayAt } from '../lib/period'
import {
  DEFAULT_PREFERENCES,
  preferencesKey,
  savePreferences,
} from '../lib/preferences'
import { dashboardSummaryQueryKey } from '../model/useDashboardSummary'
import { DashboardPage } from './DashboardPage'

// Без DOM в тестах (environment: node) страница проверяется статическим
// рендером: кэш запросов заполнен заранее, как после ответа сервера,
// а localStorage — заглушкой. Так видно, что страница берёт данные
// своей компании и учитывает сохранённые настройки.

type Summary = components['schemas']['DashboardSummaryResponse']

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'
const EMAIL = 'seller@example.com'

function memoryStorage(): Storage {
  const values = new Map<string, string>()

  return {
    get length() {
      return values.size
    },
    clear: () => {
      values.clear()
    },
    getItem: (key) => values.get(key) ?? null,
    key: (index) => [...values.keys()][index] ?? null,
    removeItem: (key) => {
      values.delete(key)
    },
    setItem: (key, value) => {
      values.set(key, value)
    },
  }
}

function summary(ordersAmountMinor: number): Summary {
  const metrics = {
    ordersAmountMinor,
    ordersQuantity: 3,
    salesAmountMinor: 0,
    salesQuantity: 1,
    returnsAmountMinor: 0,
    returnsQuantity: 0,
    advertisingMinor: 0,
    deliveryLogisticsMinor: 0,
    deliveryCount: 0,
    returnLogisticsMinor: 0,
    returnLogisticsCount: 0,
    buyoutOrderedQuantity: 3,
  }
  const period = {
    from: '2026-10-08',
    to: '2026-10-14',
    dataComplete: true,
    daysWithoutAccruals: 0,
    metrics,
    days: [],
  }

  return { currency: 'RUB', current: period, comparison: period, changes: {} }
}

function render(companyId: string): string {
  const client = new QueryClient()
  const today = todayAt()
  const selection = DEFAULT_PREFERENCES.selection
  const range = rangeFor(selection, today)
  client.setQueryData(authQueryKey(), { email: EMAIL, companies: [] })
  client.setQueryData(
    dashboardSummaryQueryKey(ONE, {
      current: range,
      comparison: comparisonFor(selection, range),
    }),
    summary(1_234_500),
  )

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/companies/${companyId}/dashboard`]}>
        <Routes>
          <Route
            element={<DashboardPage />}
            path="/companies/:companyId/dashboard"
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  ).replace(/\s/gu, ' ')
}

describe('главная', () => {
  it('показывает виджеты своей компании, кроме скрытых', () => {
    vi.stubGlobal('localStorage', memoryStorage())
    savePreferences(preferencesKey(EMAIL, ONE), {
      ...DEFAULT_PREFERENCES,
      hidden: ['returns'],
    })

    const html = render(ONE)

    expect(html).toContain('12 345 ₽')
    expect(html).toContain('aria-label="Продажи"')
    expect(html).not.toContain('aria-label="Возвраты"')
    expect(html).toContain('Виджеты · 7 из 8')
  })

  it('настройки одной компании не переходят на другую, чужие цифры не показываются', () => {
    vi.stubGlobal('localStorage', memoryStorage())
    savePreferences(preferencesKey(EMAIL, ONE), {
      ...DEFAULT_PREFERENCES,
      hidden: ['returns'],
    })

    const html = render(TWO)

    // У второй компании в кэше ничего нет — загрузка, а не цифры первой.
    expect(html).not.toContain('12 345 ₽')
    expect(html).toContain('aria-busy="true"')
    expect(html).toContain('Виджеты · 8 из 8')
  })

  it('все виджеты скрыты — пустое состояние с возвратом', () => {
    vi.stubGlobal('localStorage', memoryStorage())
    savePreferences(preferencesKey(EMAIL, ONE), {
      ...DEFAULT_PREFERENCES,
      hidden: [
        'orders',
        'sales',
        'buyout',
        'returns',
        'drrOrders',
        'drrSales',
        'logDelivery',
        'logReturn',
      ],
    })

    const html = render(ONE)

    expect(html).toContain('Все виджеты скрыты')
    expect(html).toContain('Показать все виджеты')
    expect(html).not.toContain('aria-label="Заказы"')
  })
})
