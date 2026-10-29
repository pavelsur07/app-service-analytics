import { existsSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import {
  expect,
  type APIResponse,
  type Locator,
  type Page,
  type Route,
} from '@playwright/test'
import type { components, paths } from '../../src/api/schema'

interface Fixture {
  companyId: string
  accountId: string
  from: string
  to: string
  sku: string
  runId: string
}
const statePath = 'api/var/e2e-sales-planning-state.json'
const ajax = { 'X-Conwix-Request': '1' }

type Detail = components['schemas']['SalesPlanDetailResponse']
type Calendar = components['schemas']['SalesPlanCalendarResponse']
type Performance = components['schemas']['SalesPlanPerformanceResponse']
type CalendarWrite =
  paths['/api/companies/{companyId}/sales-plans/{planId}/calendar']['put']['requestBody']['content']['application/json']

function fixture(): Fixture {
  const parsed: unknown = JSON.parse(readFileSync(statePath, 'utf8'))
  if (typeof parsed !== 'object' || parsed === null)
    throw new Error('Missing E2E planning fixture.')
  const data = parsed as Record<string, unknown>
  const string = (key: string): string => {
    const value = data[key]
    if (typeof value !== 'string')
      throw new Error(`Invalid planning fixture field: ${key}`)
    return value
  }
  return {
    companyId: string('companyId'),
    accountId: string('accountId'),
    from: string('from'),
    to: string('to'),
    sku: string('sku'),
    runId: string('runId'),
  }
}

async function json<T>(response: APIResponse): Promise<T> {
  expect(response.ok(), await response.text()).toBe(true)
  return (await response.json()) as T
}
function apiBase(data: Fixture): string {
  return `/api/companies/${data.companyId}/sales-plans`
}
async function detail(page: Page, data: Fixture, id: string): Promise<Detail> {
  return json<Detail>(
    await page.request.get(`${apiBase(data)}/${id}?groupBy=day`),
  )
}
async function calendar(
  page: Page,
  data: Fixture,
  id: string,
): Promise<Calendar> {
  return json<Calendar>(
    await page.request.get(`${apiBase(data)}/${id}/calendar?limit=200`),
  )
}
async function performance(
  page: Page,
  data: Fixture,
  id: string,
  groupBy: 'period' | 'day' = 'period',
): Promise<Performance> {
  const search = new URLSearchParams({
    from: data.from,
    to: data.to,
    groupBy,
  })
  return json<Performance>(
    await page.request.get(
      `${apiBase(data)}/${id}/performance?${search.toString()}`,
    ),
  )
}
function planId(page: Page): string {
  const id = new URL(page.url()).pathname.split('/').at(-1)
  if (id === undefined || id === '')
    throw new Error('Plan route was not opened.')
  return id
}
function metric(
  group: Locator,
  label: string,
  position: 'first' | 'last' = 'last',
): Locator {
  const cells = group
    .getByRole('row')
    .filter({
      has: group
        .page()
        .getByRole('rowheader')
        .getByText(label, { exact: true }),
    })
    .getByRole('cell')
  return position === 'first' ? cells.first() : cells.last()
}

async function setSku(
  page: Page,
  data: Fixture,
  quantity: string,
  price: string,
  verifyCalendarReadiness = false,
): Promise<void> {
  await page.getByLabel('Товар из каталога подключения').fill(data.sku)
  await page.getByRole('button', { name: new RegExp(`^${data.sku}`) }).click()
  await page.getByLabel('Штук за период', { exact: true }).fill(quantity)
  await page.getByLabel('РРЦ за штуку, ₽', { exact: true }).fill(price)
  const id = planId(page)
  const headerUrl = `**/sales-plans/${id}?*`
  const calendarUrl = `**/sales-plans/${id}/calendar?*`
  let release: () => void = () => undefined
  const reread = new Promise<void>((resolve) => {
    release = () => resolve()
  })
  let headersHeld = 0
  let calendarsHeld = 0
  const holdHeader = async (route: Route) => {
    headersHeld += 1
    await reread
    await route.continue()
  }
  const holdCalendar = async (route: Route) => {
    calendarsHeld += 1
    await reread
    await route.continue()
  }
  if (verifyCalendarReadiness) {
    const before = await detail(page, data, id)
    await expect(
      page.getByRole('region', { name: 'Календарь плана', exact: true }),
    ).toContainText(new RegExp(`Версия календаря: ${before.plan.version}`))
    await page.route(headerUrl, holdHeader)
    await page.route(calendarUrl, holdCalendar)
  }
  const saved = page.waitForResponse(
    (response) =>
      response.url().endsWith('/skus') && response.request().method() === 'PUT',
  )
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
  expect((await saved).status()).toBe(200)
  if (verifyCalendarReadiness) {
    try {
      await expect.poll(() => headersHeld > 0 && calendarsHeld > 0).toBe(true)
      await expect(
        page.getByRole('button', { name: 'Добавить событие', exact: true }),
      ).toBeDisabled()
    } finally {
      release()
      await page.unroute(headerUrl, holdHeader)
      await page.unroute(calendarUrl, holdCalendar)
    }
    const current = await detail(page, data, id)
    await expect(
      page.getByRole('region', { name: 'Календарь плана', exact: true }),
    ).toContainText(new RegExp(`Версия календаря: ${current.plan.version}`))
  }
  await expect(page.getByLabel('Штук за период', { exact: true })).toHaveValue(
    '',
  )
}
async function addEvent(
  page: Page,
  data: Fixture,
  kind: 'sales' | 'replenishment' | 'replenishment_stop',
): Promise<void> {
  await page
    .getByRole('button', { name: 'Добавить событие', exact: true })
    .click()
  await page.getByLabel('Вид события').selectOption(kind)
  await page
    .getByLabel('SKU события')
    .fill(kind === 'replenishment' ? data.sku : '')
  await page
    .getByLabel('Дата начала события')
    .fill(kind === 'replenishment_stop' ? data.to : data.from)
  if (kind === 'sales')
    await page.getByLabel('Дата окончания события').fill(data.to)
  await page
    .getByRole('button', { name: 'Применить событие', exact: true })
    .click()
}

export async function verifySalesPlanning(page: Page): Promise<void> {
  page.setDefaultTimeout(15_000)
  expect(
    existsSync(statePath),
    'Run through the guarded make test-e2e feed',
  ).toBe(true)
  const data = fixture()
  // Continue the authenticated seller company-switch scenario.
  await page.goto('/companies')
  await expect(page).toHaveURL('/companies')
  await page
    .getByRole('link', { name: 'E2E Sales Planning', exact: true })
    .click()
  await page
    .getByRole('navigation', { name: 'Разделы компании' })
    .getByRole('link', { name: 'План продаж', exact: true })
    .click()

  await page.getByRole('button', { name: 'Новый план', exact: true }).click()
  await page.getByLabel('Название', { exact: true }).fill('E2E исходная цель')
  await page.getByLabel('С', { exact: true }).fill(data.from)
  await page.getByLabel('По (включительно)').fill(data.to)
  await page
    .getByRole('button', { name: 'Создать черновик', exact: true })
    .click()
  await expect(
    page.getByRole('heading', { name: 'E2E исходная цель', exact: true }),
  ).toBeVisible()
  const baseline = planId(page)
  await page
    .getByRole('radio', {
      name: 'Продажи за вычетом уже состоявшихся возвратов',
      exact: true,
    })
    .check()
  await page
    .getByRole('button', { name: 'Сохранить смысл цели', exact: true })
    .click()
  await expect
    .poll(async () => (await detail(page, data, baseline)).plan.metric)
    .toBe('retained_sales')
  await setSku(page, data, '9', '200', true)

  await expect(
    page.getByRole('heading', { name: 'Календарь плана', exact: true }),
  ).toBeVisible()
  await addEvent(page, data, 'sales')
  await addEvent(page, data, 'replenishment')
  const savedCalendar = page.waitForResponse(
    (response) =>
      response.url().endsWith('/calendar') &&
      response.request().method() === 'PUT',
  )
  await page
    .getByRole('button', { name: 'Сохранить календарь', exact: true })
    .click()
  expect((await savedCalendar).status()).toBe(200)
  const originalCalendar = await calendar(page, data, baseline)
  expect(originalCalendar.entries).toHaveLength(2)
  expect(originalCalendar.entries.map((entry) => entry.dateFrom)).toEqual([
    data.from,
    data.from,
  ])
  expect(new Set(originalCalendar.entries.map((entry) => entry.kind))).toEqual(
    new Set(['sales', 'replenishment']),
  )
  expect(originalCalendar.seasonStart.date).toBe(data.from)
  expect(originalCalendar.seasonEnd.date).toBe(data.to)
  await page
    .getByRole('button', { name: 'Утвердить исходную цель', exact: true })
    .click()
  await expect(
    page.getByRole('heading', {
      name: 'Сравнение с исходной целью сезона',
      exact: true,
    }),
  ).toBeVisible()
  const add = page.getByRole('button', {
    name: 'Добавить событие',
    exact: true,
  })
  await expect
    .poll(async () => (await add.count()) === 0 || (await add.isDisabled()))
    .toBe(true)
  await expect(page.getByText('Добавить SKU', { exact: true })).toBeHidden()
  const region = page.getByRole('region', {
    name: 'Сравнение SKU по датам',
    exact: true,
  })
  await region.focus()
  await expect(region).toBeFocused()
  await page
    .getByRole('button', { name: 'Выбрать весь сезон', exact: true })
    .click()
  await page.getByRole('button', { name: 'Показать', exact: true }).click()
  const cell = region.getByRole('rowgroup', {
    name: `SKU ${data.sku}`,
    exact: true,
  })
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')
  await expect(metric(cell, 'Наблюдаемые продажи')).toHaveText('3')
  await expect(metric(cell, 'Выручка по ценам продаж')).toHaveText(/^300,00$/)
  await expect(metric(cell, 'Ожидаемые нетто-продажи')).toHaveText('—')
  for (const label of [
    'Ожидаемое количество по метрике цели',
    'Ожидаемое выполнение',
    'Ожидаемое отклонение от цели',
    'Ожидаемый темп метрики цели',
  ]) {
    await expect(metric(cell, label)).toHaveText('—')
  }
  await expect(metric(cell, 'Ожидаемая нетто-выручка')).toHaveText('—')
  const summary = page
    .locator('summary')
    .filter({ hasText: /^Методика нетто-прогноза$/ })
  await expect(summary).toHaveCount(1)
  const methodology = summary.locator('..')
  await summary.focus()
  await expect(summary).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(methodology).toHaveAttribute('open', '')
  await expect(methodology).toContainText(
    'Наблюдаемые продажи уже входят в него',
  )
  await expect(methodology).toContainText('Будущие заказы не учитываются')
  await expect(methodology).toContainText('60 дней от доставки')
  await page.keyboard.press('Space')
  await expect(methodology).not.toHaveAttribute('open')
  await expect(
    page
      .getByText('Оценка по этим данным не выполнялась.', { exact: true })
      .first(),
  ).toBeVisible()
  const initial = await performance(page, data, baseline)
  expect(initial.baselinePlanId).toBe(baseline)
  expect(initial.netForecast.validationStatus).toBe('validation_pending')
  expect(initial.netForecast.assessmentStatus).toBe('not_assessed')
  expect(initial.netForecast.readAt).toBeNull()
  expect(
    initial.skus[0]?.points[0]?.period.cohort?.expectedNetSalesQuantity,
  ).toBeNull()
  expect(
    initial.skus[0]?.points[0]?.period.cohort?.expectedNetRevenue,
  ).toBeNull()
  expect(initial.skus[0]?.points[0]?.period.planQuantity).toBe(9)
  expect(initial.skus[0]?.points[0]?.period.planRevenueRrp).toEqual({
    minor: 180000,
    currency: 'RUB',
  })
  expect(initial.skus[0]?.points[0]?.period.cohort?.observedRevenue).toEqual({
    minor: 30000,
    currency: 'RUB',
  })

  await page.getByRole('button', { name: 'Накопительно', exact: true }).focus()
  await page.keyboard.press('Enter')
  await expect(
    page.getByRole('button', { name: 'Накопительно', exact: true }),
  ).toHaveAttribute('aria-pressed', 'true')
  await page.getByLabel('Показать с', { exact: true }).fill(data.to)
  await page.getByRole('button', { name: 'Показать', exact: true }).click()
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')
  const sharedUrl = page.url()
  await page.reload()
  await expect(page.getByLabel('Показать с', { exact: true })).toHaveValue(
    data.to,
  )
  await expect(
    page.getByRole('button', { name: 'Накопительно', exact: true }),
  ).toHaveAttribute('aria-pressed', 'true')
  await expect(metric(cell, 'Наблюдаемые продажи')).toHaveText('3')
  await page.goBack()
  await expect(page.getByLabel('Показать с', { exact: true })).toHaveValue(
    data.from,
  )
  await page.goForward()
  await expect(page).toHaveURL(sharedUrl)
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')

  await page
    .getByRole('button', { name: 'Создать новую версию', exact: true })
    .click()
  await expect(page).not.toHaveURL(sharedUrl)
  const current = planId(page)
  expect(current).not.toBe(baseline)
  const copy = await calendar(page, data, current)
  expect(copy.entries).toHaveLength(2)
  for (const entry of copy.entries)
    expect(originalCalendar.entries.some((old) => old.id === entry.id)).toBe(
      false,
    )
  expect(
    copy.entries
      .map((entry) => ({ ...entry, id: null }))
      .sort((a, b) => a.kind.localeCompare(b.kind)),
  ).toEqual(
    originalCalendar.entries
      .map((entry) => ({ ...entry, id: null }))
      .sort((a, b) => a.kind.localeCompare(b.kind)),
  )

  await addEvent(page, data, 'replenishment_stop')
  // A header/list loading transition must preserve the existing local editor.
  await page.getByRole('button', { name: 'День', exact: true }).click()
  await expect(page.getByText('Стоп подсорт', { exact: true })).toBeVisible()
  await json(
    await page.request.put(`${apiBase(data)}/${current}/calendar`, {
      headers: ajax,
      data: {
        version: copy.version,
        entries: [
          ...copy.entries,
          {
            marketplaceSku: null,
            kind: 'replenishment',
            dateFrom: data.to,
            dateTo: data.to,
          },
        ],
      },
    }),
  )
  const staleCalendar = page.waitForResponse(
    (response) =>
      response.url().endsWith('/calendar') &&
      response.request().method() === 'PUT',
  )
  await page
    .getByRole('button', { name: 'Сохранить календарь', exact: true })
    .click()
  expect((await staleCalendar).status()).toBe(409)
  await expect(page.getByText('Стоп подсорт', { exact: true })).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Сохранить календарь', exact: true }),
  ).toBeDisabled()
  await page
    .getByRole('button', { name: 'Перечитать календарь', exact: true })
    .click()
  await expect(page.getByText('Стоп подсорт', { exact: true })).toBeVisible()
  await page
    .getByRole('button', { name: 'Отменить локальные правки', exact: true })
    .click()
  await expect(page.getByText('Стоп подсорт', { exact: true })).toHaveCount(0)
  expect((await calendar(page, data, baseline)).entries).toEqual(
    originalCalendar.entries,
  )

  await setSku(page, data, '18', '400')
  await page.getByRole('button', { name: 'День', exact: true }).click()
  await page
    .getByRole('button', {
      name: `Править ${data.sku} за ${data.from}`,
      exact: true,
    })
    .click()
  const dayForm = page
    .getByRole('heading', {
      name: `День ${data.from}, SKU ${data.sku}`,
      exact: true,
    })
    .locator('..')
  await dayForm.getByLabel('Штук в этот день', { exact: true }).fill('17')
  const beforeConcurrent = await detail(page, data, current)
  await json(
    await page.request.put(`${apiBase(data)}/${current}/days`, {
      headers: ajax,
      data: {
        version: beforeConcurrent.plan.version,
        marketplaceSku: data.sku,
        date: data.from,
        quantity: 7,
      },
    }),
  )
  const staleDay = page.waitForResponse(
    (response) =>
      response.url().endsWith('/days') && response.request().method() === 'PUT',
  )
  await dayForm
    .getByRole('button', { name: 'Сохранить день', exact: true })
    .click()
  expect((await staleDay).status()).toBe(409)
  await expect(
    dayForm.getByLabel('Штук в этот день', { exact: true }),
  ).toHaveValue('17')
  await expect(
    dayForm.getByRole('button', { name: 'Сохранить день', exact: true }),
  ).toBeDisabled()
  await dayForm.getByRole('button', { name: 'Отмена', exact: true }).click()
  expect((await detail(page, data, current)).plan.totalQuantity).toBe(19)
  await page
    .getByRole('button', { name: 'Утвердить новую версию', exact: true })
    .click()
  await expect
    .poll(async () => (await detail(page, data, current)).plan.status)
    .toBe('approved')
  await page
    .getByRole('button', { name: 'Выполнение исходной цели', exact: true })
    .click()
  await page
    .getByRole('button', { name: 'Выбрать весь сезон', exact: true })
    .click()
  await page.getByRole('button', { name: 'Показать', exact: true }).click()
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')
  const revised = await performance(page, data, current)
  expect(revised.baselinePlanId).toBe(baseline)
  expect(revised.skus[0]?.points[0]?.period.planQuantity).toBe(9)
  expect((await detail(page, data, baseline)).plan.totalQuantity).toBe(9)
  const beforeReturnDays = (await performance(page, data, current, 'day'))
    .skus[0]?.points
  expect(beforeReturnDays).toHaveLength(3)
  expect(beforeReturnDays?.[0]?.bucketFrom).toBe(data.from)
  expect(beforeReturnDays?.[2]?.bucketFrom).toBe(data.to)
  expect(beforeReturnDays?.[0]?.period.cohort?.observedNetSalesQuantity).toBe(3)
  expect(beforeReturnDays?.[0]?.period.cohort?.observedRevenue).toEqual({
    minor: 30000,
    currency: 'RUB',
  })
  expect(beforeReturnDays?.[2]?.period.cohort?.observedNetSalesQuantity).toBe(0)
  expect(beforeReturnDays?.[2]?.period.cohort?.observedRevenue).toEqual({
    minor: 0,
    currency: 'RUB',
  })

  // Import a genuine later source observation; no response mock for quantities.
  const requestPath = 'api/var/e2e-sales-planning-request.json'
  writeFileSync(
    `${requestPath}.tmp`,
    JSON.stringify({ phase: 'return_one', runId: data.runId }),
  )
  renameSync(`${requestPath}.tmp`, requestPath)
  await expect
    .poll(
      () => {
        const path = 'api/var/e2e-sales-planning-done.json'
        if (!existsSync(path)) return false
        const done: unknown = JSON.parse(readFileSync(path, 'utf8'))
        return (
          typeof done === 'object' &&
          done !== null &&
          'runId' in done &&
          done.runId === data.runId
        )
      },
      { timeout: 30_000 },
    )
    .toBe(true)
  await page.getByRole('button', { name: 'Обновить факт', exact: true }).click()
  await expect(metric(cell, 'Наблюдаемые продажи')).toHaveText('2')
  await expect(metric(cell, 'Выручка по ценам продаж')).toHaveText(/^200,00$/)
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')
  await expect(metric(cell, 'Ожидаемые нетто-продажи')).toHaveText('—')
  await expect(metric(cell, 'Ожидаемая нетто-выручка')).toHaveText('—')
  const returned = await performance(page, data, current)
  expect(returned.baselinePlanId).toBe(baseline)
  const goal = returned.skus[0]?.points[0]?.cumulative
  expect(goal?.expectedQuantityDeviation).toBeNull()
  expect(goal?.expectedGoalQuantity).toBeNull()
  expect(goal?.expectedAttainmentBps).toBeNull()
  expect(goal?.expectedSpeed).toBeNull()
  expect(goal?.forecastUnavailableReason).toBe('validation_pending')
  expect(
    returned.skus[0]?.points[0]?.cumulative.cohort?.observedNetSalesQuantity,
  ).toBe(2)
  expect(returned.skus[0]?.points[0]?.cumulative.planQuantity).toBe(9)
  expect(returned.skus[0]?.points[0]?.cumulative.observedSalesSpeed).toBe(
    '0.667',
  )

  // Season totals alone cannot distinguish a correction of the order cohort
  // from an erroneous negative sale on the later return date.
  const returnedDays = (await performance(page, data, current, 'day')).skus[0]
    ?.points
  expect(returnedDays).toHaveLength(3)
  expect(returnedDays?.[0]?.bucketFrom).toBe(data.from)
  expect(returnedDays?.[2]?.bucketFrom).toBe(data.to)
  expect(returnedDays?.[0]?.period.cohort?.observedNetSalesQuantity).toBe(2)
  expect(returnedDays?.[0]?.period.cohort?.observedRevenue).toEqual({
    minor: 20000,
    currency: 'RUB',
  })
  expect(returnedDays?.[2]?.period.cohort?.observedNetSalesQuantity).toBe(0)
  expect(returnedDays?.[2]?.period.cohort?.observedRevenue).toEqual({
    minor: 0,
    currency: 'RUB',
  })
  expect(returnedDays?.[2]?.cumulative.cohort?.observedNetSalesQuantity).toBe(2)
  expect(returnedDays?.[2]?.cumulative.cohort?.observedRevenue).toEqual({
    minor: 20000,
    currency: 'RUB',
  })
  expect(returnedDays?.[2]?.cumulative.planQuantity).toBe(9)

  await page.getByRole('button', { name: 'За интервал', exact: true }).click()
  for (const [day, quantity, revenue] of [
    [data.from, '2', /^200,00$/],
    [data.to, '0', /^0,00$/],
  ] as const) {
    await page.getByLabel('Показать с', { exact: true }).fill(day)
    await page.getByLabel('Показать по', { exact: true }).fill(day)
    await page
      .getByRole('group', { name: 'Группировка сравнения', exact: true })
      .getByRole('button', { name: 'День', exact: true })
      .click()
    await page.getByRole('button', { name: 'Показать', exact: true }).click()
    await expect(metric(cell, 'Наблюдаемые продажи')).toHaveText(quantity)
    await expect(metric(cell, 'Выручка по ценам продаж')).toHaveText(revenue)
    await expect(metric(cell, 'Исходная цель')).toHaveText('3')
  }
  await page.getByRole('button', { name: 'Накопительно', exact: true }).click()
  await expect(metric(cell, 'Наблюдаемые продажи')).toHaveText('2')
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')
  await page
    .getByRole('button', { name: 'Выбрать весь сезон', exact: true })
    .click()
  await page.getByRole('button', { name: 'Показать', exact: true }).click()
  await expect(metric(cell, 'Исходная цель')).toHaveText('9')

  // The same cumulative goal was already visible for the single return day.
  // Wait for the new season response to render before installing a failure.
  await expect(
    page.getByText(
      new RegExp(`^Окно: ${data.from} — ${data.to}\\. Накопление`),
    ),
  ).toBeVisible()
  const url = `**/sales-plans/${current}/performance?*`
  let failOnce = true
  await page.route(url, async (route) => {
    if (failOnce) {
      failOnce = false
      await route.abort('failed')
    } else await route.continue()
  })
  await page.getByRole('button', { name: 'Обновить факт', exact: true }).click()
  await expect(
    page.getByRole('button', { name: 'Перечитать план', exact: true }),
  ).toBeVisible()
  await expect(
    page.getByText('Ожидаемые нетто-продажи', { exact: true }),
  ).toHaveCount(0)
  await expect(summary).toHaveCount(0)
  await page
    .getByRole('button', { name: 'Перечитать план', exact: true })
    .click()
  await expect(metric(cell, 'Наблюдаемые продажи')).toHaveText('2')
  await expect(metric(cell, 'Ожидаемые нетто-продажи')).toHaveText('—')
  await expect(summary).toHaveCount(1)
  await page.unroute(url)
  const netGroup = metric(cell, 'Доступность нетто-прогноза')
  await netGroup.locator('summary').click()
  await expect(
    netGroup
      .locator('p')
      .filter({ hasText: 'Проверка качества прогноза ещё не завершена' }),
  ).toHaveCount(1)
  await expect(netGroup.getByText(/^Продажи и выручка:/)).toBeVisible()
  const geometry = () =>
    region.evaluate((element) => {
      const main = element.closest('main')
      if (main === null) throw new Error('Missing real scroll owner')
      return {
        viewport: innerWidth,
        pageWidth: document.documentElement.scrollWidth,
        mainWidth: main.clientWidth,
        mainScrollWidth: main.scrollWidth,
        regionRight: element.getBoundingClientRect().right,
        regionWidth: element.clientWidth,
        regionScrollWidth: element.scrollWidth,
      }
    })
  await netGroup.scrollIntoViewIfNeeded()
  await expect(netGroup).toBeInViewport()
  const wideGeometry = await geometry()
  expect(wideGeometry.pageWidth).toBeLessThanOrEqual(wideGeometry.viewport)
  expect(wideGeometry.regionRight).toBeLessThanOrEqual(wideGeometry.viewport)
  await netGroup.screenshot({ path: 'var/b13-net-wide.png' })
  const originalViewport = page.viewportSize()
  await page.setViewportSize({ width: 390, height: 844 })
  await summary.focus()
  await page.keyboard.press('Enter')
  await expect(methodology).toHaveAttribute('open', '')
  await expect(methodology).toContainText('дней от доставки')
  // The existing fixed sidebar leaves a very narrow main area at phone width.
  // Keep this evidence separate; B13 does not redesign the shared shell.
  await page.screenshot({ path: 'var/b13-shell-390.png', fullPage: true })
  await page.keyboard.press('Space')
  await expect(methodology).not.toHaveAttribute('open')
  await page.setViewportSize({ width: 768, height: 1024 })
  await summary.focus()
  await page.keyboard.press('Enter')
  await expect(methodology).toHaveAttribute('open', '')
  await methodology.screenshot({ path: 'var/b13-methodology-narrow.png' })
  await page.keyboard.press('Space')
  await expect(methodology).not.toHaveAttribute('open')
  await netGroup.scrollIntoViewIfNeeded()
  await expect(netGroup).toBeInViewport()
  const narrowGeometry = await geometry()
  expect(narrowGeometry.pageWidth).toBeLessThanOrEqual(narrowGeometry.viewport)
  expect(narrowGeometry.regionRight).toBeLessThanOrEqual(
    narrowGeometry.viewport,
  )
  await netGroup.screenshot({ path: 'var/b13-net-narrow.png' })
  writeFileSync(
    'var/b13-layout-evidence.json',
    JSON.stringify(
      { wide: wideGeometry, narrowerDesktop: narrowGeometry },
      null,
      2,
    ),
  )
  if (originalViewport !== null) await page.setViewportSize(originalViewport)
  await page.screenshot({
    path: 'var/sales-planning-performance-browser.png',
    fullPage: true,
  })
  await region.screenshot({
    path: 'var/sales-planning-performance-grid-browser.png',
  })

  // A 500-event source must keep the DOM small and submit all 499 remaining rows.
  const shift = (value: string, days: number) =>
    new Date(Date.parse(`${value}T00:00:00Z`) + days * 86400000)
      .toISOString()
      .slice(0, 10)
  const monthFrom = shift(data.from, -31)
  const monthTo = shift(data.from, -1)
  const large = await json<components['schemas']['SalesPlanCreatedResponse']>(
    await page.request.post(apiBase(data), {
      headers: ajax,
      data: {
        marketplaceAccountId: data.accountId,
        name: 'E2E большой календарь',
        dateFrom: monthFrom,
        dateTo: monthTo,
        currency: 'RUB',
      },
    }),
  )
  const many: CalendarWrite['entries'] = []
  for (let from = 0; from < 31; from += 1) {
    for (let to = from; to < 31; to += 1)
      many.push({
        marketplaceSku: null,
        kind: 'sales',
        dateFrom: shift(monthFrom, from),
        dateTo: shift(monthFrom, to),
      })
  }
  for (let index = 0; index < 4; index += 1)
    many.push({
      marketplaceSku: null,
      kind: 'replenishment',
      dateFrom: shift(monthFrom, index),
      dateTo: shift(monthFrom, index),
    })
  expect(many).toHaveLength(500)
  const largeHeader = await detail(page, data, large.id)
  await json(
    await page.request.put(`${apiBase(data)}/${large.id}/calendar`, {
      headers: ajax,
      data: {
        version: largeHeader.plan.version,
        entries: many,
      },
    }),
  )
  await page.goto(`/companies/${data.companyId}/sales-plans/${large.id}`)
  const calendarTable = page.getByRole('table', {
    name: 'Ручные события',
    exact: true,
  })
  await expect(calendarTable).toHaveAttribute('aria-rowcount', '501')
  expect(await calendarTable.getByRole('row').count()).toBeLessThan(201)
  const scroll = page.getByRole('region', {
    name: 'Ручные события: прокручиваемый список',
    exact: true,
  })
  await scroll.focus()
  await page.keyboard.press('End')
  await page.keyboard.press('Tab')
  await expect(
    page.getByRole('button', { name: 'Изменить событие 500', exact: true }),
  ).toBeFocused()
  await page.keyboard.press('Tab')
  const deleteLast = page.getByRole('button', {
    name: 'Удалить событие 500',
    exact: true,
  })
  await expect(deleteLast).toBeFocused()
  await page.keyboard.press('Enter')
  const savedLarge = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/${large.id}/calendar`) &&
      response.request().method() === 'PUT',
  )
  const fullPut = page.waitForRequest(
    (request) =>
      request.url().endsWith(`/${large.id}/calendar`) &&
      request.method() === 'PUT',
  )
  await page
    .getByRole('button', { name: 'Сохранить календарь', exact: true })
    .click()
  const sent: unknown = (await fullPut).postDataJSON()
  expect(typeof sent).toBe('object')
  if (
    typeof sent !== 'object' ||
    sent === null ||
    !('entries' in sent) ||
    !Array.isArray(sent.entries)
  )
    throw new Error('Missing full calendar command.')
  expect(sent.entries).toHaveLength(499)
  expect((await savedLarge).status()).toBe(200)
  await expect(calendarTable).toHaveAttribute('aria-rowcount', '500')
  let long = await detail(page, data, large.id)
  await json(
    await page.request.put(`${apiBase(data)}/${large.id}/metric`, {
      headers: ajax,
      data: { version: long.plan.version, metric: 'retained_sales' },
    }),
  )
  long = await detail(page, data, large.id)
  await json(
    await page.request.put(`${apiBase(data)}/${large.id}/skus`, {
      headers: ajax,
      data: {
        version: long.plan.version,
        items: [
          { marketplaceSku: data.sku, totalQuantity: 31, priceMinor: 10000 },
        ],
      },
    }),
  )
  long = await detail(page, data, large.id)
  await json(
    await page.request.post(`${apiBase(data)}/${large.id}/approve`, {
      headers: ajax,
      data: { version: long.plan.version },
    }),
  )
  await page.reload()
  const wide = page.getByRole('region', {
    name: 'Сравнение SKU по датам',
    exact: true,
  })
  await expect(wide).toBeVisible()
  await wide.focus()
  await expect(wide).toBeFocused()
  expect(
    await wide.evaluate((element) => element.scrollWidth > element.clientWidth),
  ).toBe(true)
  await page.keyboard.press('ArrowRight')
  await expect
    .poll(() => wide.evaluate((element) => element.scrollLeft))
    .toBeGreaterThan(0)
  const pinnedMetric = wide
    .getByRole('rowheader')
    .filter({ hasText: /^Исходная цель/ })
    .first()
  const pinnedBefore = await pinnedMetric.boundingBox()
  expect(pinnedBefore).not.toBeNull()
  await wide.evaluate((element) => {
    element.scrollLeft = 400
  })
  await expect
    .poll(() => wide.evaluate((element) => element.scrollLeft))
    .toBeGreaterThan(100)
  const pinnedAfter = await pinnedMetric.boundingBox()
  expect(pinnedAfter?.x).toBeCloseTo(pinnedBefore?.x ?? -1, 0)
  await expect(
    metric(
      wide.getByRole('rowgroup', { name: `SKU ${data.sku}`, exact: true }),
      'Исходная цель',
    ),
  ).not.toContainText('шт.')
  await wide.locator('thead').scrollIntoViewIfNeeded()
  await page.screenshot({ path: 'var/b13-grid-header-wide.png' })
  const gridViewport = page.viewportSize()
  await page.setViewportSize({ width: 768, height: 1024 })
  await wide.focus()
  await expect(wide).toBeFocused()
  const beforeNarrowScroll = await wide.evaluate(
    (element) => element.scrollLeft,
  )
  await page.keyboard.press('ArrowRight')
  await expect
    .poll(() => wide.evaluate((element) => element.scrollLeft))
    .toBeGreaterThan(beforeNarrowScroll)
  await wide.locator('thead').scrollIntoViewIfNeeded()
  await page.screenshot({ path: 'var/b13-grid-header-narrow.png' })
  if (gridViewport !== null) await page.setViewportSize(gridViewport)
  const emptyFact = wide.getByRole('rowgroup', {
    name: `SKU ${data.sku}`,
    exact: true,
  })
  await expect(metric(emptyFact, 'Наблюдаемые продажи', 'first')).toHaveText(
    '—',
  )

  const conflict = await json<
    components['schemas']['SalesPlanCreatedResponse']
  >(
    await page.request.post(apiBase(data), {
      headers: ajax,
      data: {
        marketplaceAccountId: data.accountId,
        name: 'E2E пересечение',
        dateFrom: data.from,
        dateTo: data.to,
        currency: 'RUB',
      },
    }),
  )
  let competing = await detail(page, data, conflict.id)
  await json(
    await page.request.put(`${apiBase(data)}/${conflict.id}/metric`, {
      headers: ajax,
      data: { version: competing.plan.version, metric: 'retained_sales' },
    }),
  )
  competing = await detail(page, data, conflict.id)
  await json(
    await page.request.put(`${apiBase(data)}/${conflict.id}/skus`, {
      headers: ajax,
      data: {
        version: competing.plan.version,
        items: [
          { marketplaceSku: data.sku, totalQuantity: 1, priceMinor: 10000 },
        ],
      },
    }),
  )
  await page.goto(`/companies/${data.companyId}/sales-plans/${conflict.id}`)
  await page
    .getByRole('button', { name: 'Утвердить исходную цель', exact: true })
    .click()
  await expect(page.getByRole('alert')).toContainText(
    'Дни уже заняты утверждённым планом',
  )
  await expect(page.getByRole('alert')).not.toContainText('План изменился')

  const second = process.env.E2E_SECOND_COMPANY_ID ?? ''
  const foreignPlan = await page.request.get(
    `/api/companies/${second}/sales-plans/${current}`,
  )
  expect(foreignPlan.status()).toBe(404)
  await page.goto(`/companies/${data.companyId}/sales-plans/${current}`)
  await expect(summary).toHaveCount(1)
  await expect(
    page.getByText('Ожидаемые нетто-продажи', { exact: true }).first(),
  ).toBeVisible()
  await page
    .getByRole('banner')
    .getByRole('link', { name: 'E2E Sales Planning', exact: true })
    .click()
  await page.getByRole('link', { name: 'E2E Sandbox Two', exact: true }).click()
  await page
    .getByRole('navigation', { name: 'Разделы компании' })
    .getByRole('link', { name: 'План продаж', exact: true })
    .click()
  await expect(
    page.getByRole('link', { name: 'E2E исходная цель', exact: true }),
  ).toHaveCount(0)
  await expect(page.getByText(data.sku, { exact: true })).toHaveCount(0)
  const other = await json<components['schemas']['SalesPlanListResponse']>(
    await page.request.get(`/api/companies/${second}/sales-plans`),
  )
  expect(other.items).toEqual([])
  await expect(summary).toHaveCount(0)
  await expect(
    page.getByText('Ожидаемые нетто-продажи', { exact: true }),
  ).toHaveCount(0)
}
