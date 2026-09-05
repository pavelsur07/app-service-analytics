import { expect, test } from '@playwright/test'

import type { components } from '../../src/api/schema'

type BuyoutDailyResponse = components['schemas']['BuyoutDailyResponse']

const companyId = process.env.E2E_COMPANY_ID
const userEmail = process.env.E2E_USER_EMAIL
const userPassword = process.env.E2E_USER_PASSWORD

test.describe('buyout rate', () => {
  test.beforeEach(() => {
    test.skip(
      companyId === undefined ||
        userEmail === undefined ||
        userPassword === undefined,
      'E2E_COMPANY_ID/E2E_USER_EMAIL/E2E_USER_PASSWORD не заданы — запускать через make test-e2e',
    )
  })

  test('seller changes period, pages SKUs and opens daily dynamics', async ({
    page,
  }) => {
    test.setTimeout(90_000)
    await page.goto('/login')
    await page.getByLabel('Email').fill(userEmail ?? '')
    await page.getByLabel('Пароль').fill(userPassword ?? '')
    await page.getByRole('button', { name: 'Войти' }).click()
    await page.getByRole('link', { name: 'E2E Sandbox LLC' }).click()

    const nav = page.getByRole('navigation', { name: 'Разделы компании' })
    // Сайдбар не пуст физически до этого момента — ConnectionGate
    // (app/CompanyLayout.tsx) рендерит null, пока список подключений
    // не прочитан, и появляется вместе с оболочкой одним кадром позже.
    // allTextContents() не ждёт этого сама, поэтому первый пункт ждём
    // явно; count()/toEqual ниже уже застают сайдбар отрисованным.
    await expect(nav.getByRole('link', { name: 'Продажи' })).toBeVisible()
    const firstLinks = await nav.getByRole('link').allTextContents()
    expect(firstLinks.slice(0, 2)).toEqual(['Продажи', 'Выкуп'])

    await nav.getByRole('link', { name: 'Выкуп' }).click()
    await expect(page).toHaveURL(
      `/companies/${companyId}/redemption?days=30&sort=ordered&direction=desc`,
    )
    await expect(page.getByRole('heading', { name: 'Выкуп' })).toBeVisible()

    // Account/SKU baseline меньше 30 resolved quantity: отсутствие
    // прогноза остаётся честным текстом, а не превращается в 0%.
    await expect(
      page.getByText(
        /Недостаточно данных для прогноза выкупа · 77,78% заказов разрешилось · ожидается — выкупленных шт/,
      ),
    ).toBeVisible()
    await expect(page.getByText(/Предварительно/).first()).toBeVisible()
    await expect(
      page.getByRole('row').filter({ hasText: 'SKU 100001' }),
    ).toContainText('P 100% от заказанных')
    await expect(
      page.getByRole('row').filter({ hasText: 'SKU 100003' }),
    ).toContainText('T2 100% · P 0% от заказанных')
    await expect(
      page.getByRole('row').filter({ hasText: 'SKU 100004' }),
    ).toContainText('T1 100% · T2 0%')

    const deliveredSku = page.getByRole('row').filter({ hasText: 'SKU 100002' })
    await expect(deliveredSku).toContainText('100%')
    await expect(deliveredSku).toContainText('3 из 3 шт')

    const dailyResponse = page.waitForResponse(
      (response) =>
        response.url().includes('/buyout-rate/100002/daily?days=30') &&
        response.status() === 200,
    )
    await deliveredSku
      .getByRole('button', { name: 'Показать динамику артикула 100002' })
      .click()
    const dailyPayload = (await (
      await dailyResponse
    ).json()) as BuyoutDailyResponse
    expect(dailyPayload).toEqual({
      marketplaceSku: '100002',
      series: [
        {
          date: moscowDay(20),
          actualBuyoutRateBps: 10000,
          projectedBuyoutRateBps: 10000,
          resolutionRateBps: 10000,
          orderedQuantity: 2,
          resolvedQuantity: 2,
          projectedBuyoutQuantity: 2,
        },
        {
          date: moscowDay(19),
          actualBuyoutRateBps: 10000,
          projectedBuyoutRateBps: 10000,
          resolutionRateBps: 10000,
          orderedQuantity: 1,
          resolvedQuantity: 1,
          projectedBuyoutQuantity: 1,
        },
      ],
    })
    const chart = page.getByRole('img', {
      name: 'Динамика фактического и прогнозного процента выкупа',
    })
    await expect(chart).toBeVisible()
    const visibleSeries = chart.locator('.recharts-line-curve')
    await expect(visibleSeries).toHaveCount(2)
    await expect(visibleSeries.nth(0)).toHaveAttribute('d', /L/)
    await expect(visibleSeries.nth(1)).toHaveAttribute('d', /L/)
    await expect(
      page.getByRole('table', { name: 'Значения дневного графика выкупа' }),
    ).toContainText('100%')

    await page.getByRole('button', { name: '7 дней' }).click()
    await expect(page).toHaveURL(
      `/companies/${companyId}/redemption?days=7&sort=ordered&direction=desc`,
    )
    await expect(page.getByText('Пока считать нечего')).toBeVisible()

    const restoredThirtyDayResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('days') === '30' &&
        response.status() === 200
      )
    })
    await page.getByRole('button', { name: '30 дней' }).click()
    await restoredThirtyDayResponse
    await expect(page).toHaveURL(
      `/companies/${companyId}/redemption?days=30&sort=ordered&direction=desc`,
    )
    await expect(
      page.getByRole('img', {
        name: 'Динамика фактического и прогнозного процента выкупа',
      }),
    ).toHaveCount(0)

    const orderedHeader = page.getByRole('columnheader', {
      name: 'Заказано, шт.',
    })
    const actualHeader = page.getByRole('columnheader', {
      name: 'Фактический выкуп, %',
    })
    await expect(orderedHeader).toHaveAttribute('aria-sort', 'descending')

    const orderedAscResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('sort') === 'ordered' &&
        url.searchParams.get('direction') === 'asc' &&
        response.status() === 200
      )
    })
    await orderedHeader.getByRole('button').click()
    await orderedAscResponse
    await expect(orderedHeader).toHaveAttribute('aria-sort', 'ascending')
    await expect(page).toHaveURL(/sort=ordered&direction=asc/)

    const actualDescResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('sort') === 'actual_buyout' &&
        url.searchParams.get('direction') === 'desc' &&
        response.status() === 200
      )
    })
    await actualHeader.getByRole('button').click()
    await actualDescResponse
    await expect(actualHeader).toHaveAttribute('aria-sort', 'descending')
    await expect(page.locator('tbody > tr').first()).toContainText('SKU 100002')
    await expect(page).toHaveURL(/sort=actual_buyout&direction=desc/)

    const orderedDescResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('sort') === 'ordered' &&
        url.searchParams.get('direction') === 'desc' &&
        response.status() === 200
      )
    })
    await orderedHeader.getByRole('button').click()
    await orderedDescResponse

    // 90 дней включают большую зафиксированную posting fixture; UI limit=10
    // делает keyset-пагинацию наблюдаемой в пользовательском сценарии.
    await page.getByRole('button', { name: '90 дней' }).click()
    await expect(page).toHaveURL(
      `/companies/${companyId}/redemption?days=90&sort=ordered&direction=desc`,
    )

    const rows = page.locator('tbody > tr')
    await expect(rows).toHaveCount(10, { timeout: 30_000 })
    const firstPage = await rows.allTextContents()

    const secondPageResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('days') === '90' &&
        url.searchParams.has('cursor') &&
        response.status() === 200
      )
    })
    await page.getByRole('button', { name: 'Дальше' }).click()
    await secondPageResponse
    await expect(page).toHaveURL(
      /redemption\?days=90&sort=ordered&direction=desc&cursor=/,
    )
    await expect.poll(() => rows.allTextContents()).not.toEqual(firstPage)
    const secondPage = await rows.allTextContents()
    expect(secondPage.filter((row) => firstPage.includes(row))).toEqual([])

    await page.reload()
    await expect(page.getByRole('button', { name: 'Назад' })).toBeDisabled({
      timeout: 30_000,
    })

    // Выбор текущего периода сбрасывает deep-link cursor на первую страницу;
    // обычный переход вперёд после этого сохраняет честную локальную историю.
    const firstPageResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('days') === '90' &&
        !url.searchParams.has('cursor') &&
        response.status() === 200
      )
    })
    await page.getByRole('button', { name: '90 дней' }).click()
    await firstPageResponse
    await expect(page).toHaveURL(
      `/companies/${companyId}/redemption?days=90&sort=ordered&direction=desc`,
    )
    await expect(rows).toHaveCount(10)
    expect(await rows.allTextContents()).toEqual(firstPage)

    const secondPageAgainResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('days') === '90' &&
        url.searchParams.has('cursor') &&
        response.status() === 200
      )
    })
    await page.getByRole('button', { name: 'Дальше' }).click()
    await secondPageAgainResponse
    await expect(page).toHaveURL(
      /redemption\?days=90&sort=ordered&direction=desc&cursor=/,
    )

    const pagedUrl = page.url()
    await page
      .getByRole('button', { name: /Показать динамику артикула/ })
      .first()
      .click()
    await expect(
      page.getByRole('img', {
        name: 'Динамика фактического и прогнозного процента выкупа',
      }),
    ).toBeVisible()
    await expect(
      page.locator('span').filter({ hasText: /^Факт$/ }),
    ).toBeVisible()
    await expect(
      page.locator('span').filter({ hasText: /^Прогноз$/ }),
    ).toBeVisible()
    expect(page.url()).toBe(pagedUrl)

    const backResponse = page.waitForResponse((response) => {
      const url = new URL(response.url())
      return (
        url.pathname.endsWith('/buyout-rate') &&
        url.searchParams.get('days') === '90' &&
        !url.searchParams.has('cursor') &&
        response.status() === 200
      )
    })
    await page.getByRole('button', { name: 'Назад' }).click()
    await backResponse
    await expect(page).toHaveURL(
      `/companies/${companyId}/redemption?days=90&sort=ordered&direction=desc`,
    )
    await expect(rows).toHaveCount(10)
    expect(await rows.allTextContents()).toEqual(firstPage)
  })
})

function moscowDay(daysAgo: number): string {
  const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Moscow',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
  const day = new Date(`${today}T12:00:00Z`)
  day.setUTCDate(day.getUTCDate() - daysAgo)

  return day.toISOString().slice(0, 10)
}
