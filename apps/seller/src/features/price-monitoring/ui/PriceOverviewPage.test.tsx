import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, it } from 'vitest'

import {
  priceOverviewQueryKey,
  type PriceOverviewItem,
} from '../model/usePriceOverview'
import { PriceOverviewPage } from './PriceOverviewPage'

const COMPANY_ID = '019ffe00-0000-7000-8000-000000000001'
const NAME =
  'Лосины женские спортивные черные легинсы пуш ап для фитнеса утягивающие Sexy'

function renderOverview(item: PriceOverviewItem) {
  const client = new QueryClient({
    defaultOptions: { queries: { staleTime: Infinity, retry: false } },
  })
  client.setQueryData(priceOverviewQueryKey(COMPANY_ID), { items: [item] })

  try {
    return renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[`/companies/${COMPANY_ID}/prices`]}>
          <Routes>
            <Route
              path="/companies/:companyId/prices"
              element={<PriceOverviewPage />}
            />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    )
  } finally {
    client.clear()
  }
}

describe('ссылка на товар в отчёте цен', () => {
  it('открывает карточку Ozon по названию в новой вкладке', () => {
    const markup = renderOverview({ marketplaceSku: '2029269084', name: NAME })

    expect(markup).toMatch(
      /<a[^>]*href="https:\/\/www\.ozon\.ru\/product\/2029269084\/"[^>]*target="_blank"[^>]*rel="noopener noreferrer"[^>]*>/,
    )
    expect(markup).toContain(`${NAME}</a>`)
    expect(markup).toContain('2029269084</div>')
  })

  it.each([null, undefined, ''])('использует SKU без названия %j', (name) => {
    const markup = renderOverview({
      marketplaceSku: '2029269084',
      ...(name === undefined ? {} : { name }),
    })

    expect(markup).toContain('href="https://www.ozon.ru/product/2029269084/"')
    expect(markup).toContain('2029269084</a>')
  })

  it('оставляет название текстом при некорректном SKU', () => {
    const markup = renderOverview({ marketplaceSku: 'seller-code', name: NAME })

    expect(markup).toContain(NAME)
    expect(markup).not.toContain('<a ')
  })
})
