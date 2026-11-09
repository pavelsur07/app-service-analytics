import { expect, test, type Page } from '@playwright/test'

import {
  ADS_ACCOUNT as account,
  ADS_STAMP as stamp,
  createAdsMock,
} from '../../src/features/ozon-ads/lib/browser.fixture'

const company = process.env.E2E_COMPANY_ID ?? ''
const second = process.env.E2E_SECOND_COMPANY_ID ?? ''
const email = process.env.E2E_USER_EMAIL ?? ''
const password = process.env.E2E_USER_PASSWORD ?? ''

async function fixture(page: Page) {
  const state = createAdsMock(company, second)
  await page.clock.setFixedTime(new Date(stamp))
  // Playwright is only the transport adapter. MSW resolves typed handlers.
  await page.route(
    /\/api\/companies\/[^/]+\/(?:connections|ozon-ads\/.*|ozon-advertising\/.*)(?:\?.*)?$/,
    async (route) => {
      const request = route.request()
      const body = request.postDataBuffer()
      const response = await state.response(
        new Request(request.url(), {
          method: request.method(),
          headers: request.headers(),
          ...(body === null ? {} : { body }),
        }),
      )
      if (response === undefined)
        throw new Error(
          `Unhandled Ads mock: ${request.method()} ${new URL(request.url()).pathname}`,
        )
      if (response.type === 'error') {
        await route.abort('failed')
        return
      }
      await route.fulfill({
        status: response.status,
        headers: Object.fromEntries(response.headers),
        body: await response.text(),
      })
    },
  )
  return state
}
async function login(page: Page) {
  await page.goto('/login')
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Пароль', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await expect(page).toHaveURL(/\/companies$/)
  await page.getByRole('link', { name: 'E2E Sandbox LLC', exact: true }).click()
  await page.getByRole('link', { name: 'Озон Ads', exact: true }).click()
  await expect(
    page.getByRole('heading', { name: 'Озон Ads', exact: true }),
  ).toBeVisible()
}
async function openForm(page: Page) {
  await page.getByRole('tab', { name: 'Стратегии', exact: true }).click()
  await page.getByRole('button', { name: 'Добавить наблюдение' }).click()
  await page
    .getByRole('combobox', { name: 'Рекламное подключение', exact: true })
    .selectOption(account)
  await page
    .getByRole('combobox', { name: 'Кампания Ozon', exact: true })
    .selectOption('49761882')
  await page.getByLabel(/^Суточный лимит наблюдения, ₽/).fill('1,03')
}

test.describe('Озон Ads: интерфейс наблюдения, синтетический API', () => {
  test.setTimeout(60_000)
  test.beforeEach(() =>
    test.skip(
      !company || !second || !email || !password,
      'Нужен sandbox seed через make test-e2e',
    ),
  )
  test('Tabs, точные деньги, повторы, история и смена компании', async ({
    page,
  }) => {
    const state = await fixture(page)
    await login(page)
    await expect(
      page
        .getByRole('navigation')
        .getByRole('link', { name: 'Озон Ads', exact: true }),
    ).toHaveCount(1)
    await expect(
      page.getByRole('tab', { name: 'Кампании', exact: true }),
    ).toHaveAttribute('aria-selected', 'true')
    await page
      .getByRole('combobox', { name: 'Кабинет', exact: true })
      .selectOption(account)
    await page.getByRole('tab', { name: 'Кампании', exact: true }).focus()
    await page.keyboard.press('End')
    await expect(
      page.getByRole('tab', { name: 'Настройки', exact: true }),
    ).toBeFocused()
    await expect(page.getByRole('tabpanel')).toContainText('Условия наблюдения')
    await page.goBack()
    await expect(
      page.getByRole('tab', { name: 'Кампании', exact: true }),
    ).toHaveAttribute('aria-selected', 'true')
    await openForm(page)
    await page.getByLabel(/^Суточный лимит наблюдения, ₽/).fill('1.001')
    await expect(
      page.getByRole('button', { name: 'Сохранить наблюдение' }),
    ).toBeDisabled()
    await page.getByLabel(/^Суточный лимит наблюдения, ₽/).fill('1,03')
    await page
      .getByRole('button', { name: 'Сохранить наблюдение' })
      .evaluate((button) => {
        if (button instanceof HTMLButtonElement) {
          button.click()
          button.click()
        }
      })
    await expect(
      page.getByRole('button', { name: 'Настроить 49761882' }),
    ).toBeVisible()
    expect(state.writes()).toBe(1)
    expect(state.lists.get(company)?.[0]?.dailyLimit.amountMinor).toBe(103)
    await expect(
      page.getByText('Ожидается первый расчёт', { exact: true }),
    ).toBeVisible()
    await page
      .getByRole('tab', { name: 'История действий', exact: true })
      .click()
    await expect(page.getByRole('tabpanel')).toContainText(
      'Сохранены настройки',
    )
    await expect(
      page.getByRole('combobox', { name: 'Кабинет', exact: true }),
    ).toHaveValue(account)
    await page.reload()
    await expect(
      page.getByRole('tab', { name: 'История действий', exact: true }),
    ).toHaveAttribute('aria-selected', 'true')
    await page
      .getByRole('link', { name: 'E2E Sandbox LLC', exact: true })
      .click()
    await page
      .getByRole('link', { name: 'E2E Sandbox Two', exact: true })
      .click()
    await page.getByRole('link', { name: 'Озон Ads', exact: true }).click()
    await expect(
      page.getByRole('button', { name: 'Настроить 49761882' }),
    ).toHaveCount(0)
    await expect(
      page.getByText('Кампания другой компании', { exact: true }),
    ).toBeVisible()
    await expect(
      page.getByText('Тестовая CPC-кампания', { exact: true }),
    ).toHaveCount(0)
    await page.getByRole('tab', { name: 'Стратегии', exact: true }).click()
    await expect(
      page.getByText('Для выбранного фильтра наблюдений нет.'),
    ).toBeVisible()
    expect(state.reads.some((path) => path.includes(second))).toBe(true)
  })
  test('каталог виден при выключенном наблюдении и не показывает старую компанию во время загрузки', async ({
    page,
  }) => {
    const state = await fixture(page)
    state.disable()
    await login(page)
    await expect(
      page.getByText('Тестовая CPC-кампания', { exact: true }),
    ).toBeVisible()
    await expect(page.getByText('Работает', { exact: true })).toHaveCount(2)
    await page.getByRole('checkbox', { name: 'Показать архивные' }).click()
    await expect(page).toHaveURL(/catalogArchived=true/)
    await page.getByRole('tab', { name: 'Настройки', exact: true }).click()
    await page.getByRole('tab', { name: 'Кампании', exact: true }).click()
    await expect(
      page.getByRole('checkbox', { name: 'Показать архивные' }),
    ).toBeChecked()
    await expect(
      page.getByRole('button', { name: 'Добавить наблюдение' }),
    ).toHaveCount(0)
    state.holdCatalog(second)
    await page
      .getByRole('link', { name: 'E2E Sandbox LLC', exact: true })
      .click()
    await page
      .getByRole('link', { name: 'E2E Sandbox Two', exact: true })
      .click()
    await page.getByRole('link', { name: 'Озон Ads', exact: true }).click()
    await expect(
      page.getByText('Загружаем сохранённый список кампаний…', { exact: true }),
    ).toBeVisible()
    await expect(
      page.getByText('Тестовая CPC-кампания', { exact: true }),
    ).toHaveCount(0)
    state.releaseCatalog()
    await expect(
      page.getByText('Кампания другой компании', { exact: true }),
    ).toBeVisible()
    expect(state.writes()).toBe(0)
    expect(
      state.reads.filter((path) => /\/ozon-ads\/(policies|events)$/.test(path)),
    ).toEqual([])
  })
  test('несохранённый ввод и неопределённый результат не теряются и не повторяются', async ({
    page,
  }) => {
    const state = await fixture(page)
    await login(page)
    await openForm(page)
    await page
      .getByRole('tab', { name: 'История действий', exact: true })
      .click()
    await expect(
      page.getByText('Есть несохранённые изменения. Покинуть форму?'),
    ).toBeVisible()
    await page.getByRole('button', { name: 'Остаться', exact: true }).click()
    await expect(page.getByLabel(/^Суточный лимит наблюдения, ₽/)).toHaveValue(
      '1,03',
    )
    state.setUnknown()
    await page.getByRole('button', { name: 'Сохранить наблюдение' }).click()
    await expect(
      page.getByText(/Результат сохранения неизвестен/),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Сохранить наблюдение' }),
    ).toBeDisabled()
    expect(state.writes()).toBe(1)
    await page.getByRole('button', { name: 'Закрыть форму' }).click()
    await page
      .getByRole('button', { name: 'Отбросить изменения', exact: true })
      .click()
    await page
      .getByRole('tab', { name: 'История действий', exact: true })
      .click()
    await expect(
      page.getByText('Событий для выбранного кабинета пока нет.'),
    ).toBeVisible()
  })
  test('ошибка фоновой проверки сохраняет черновик, незавершённая запись блокирует переход', async ({
    page,
  }) => {
    const state = await fixture(page)
    await login(page)
    await openForm(page)
    state.gateError(true)
    await page.clock.setFixedTime(new Date('2026-11-08T10:02:00Z'))
    await page.evaluate(() =>
      window.dispatchEvent(new Event('visibilitychange')),
    )
    await expect(
      page.getByRole('button', { name: 'Повторить проверку' }),
    ).toBeVisible()
    await expect(page.getByLabel(/^Суточный лимит наблюдения, ₽/)).toHaveValue(
      '1,03',
    )
    await expect(
      page.getByRole('button', { name: 'Сохранить наблюдение' }),
    ).toBeDisabled()
    state.gateError(false)
    await page.getByRole('button', { name: 'Повторить проверку' }).click()
    await expect(
      page.getByRole('button', { name: 'Сохранить наблюдение' }),
    ).toBeEnabled()
    await expect(page.getByLabel(/^Суточный лимит наблюдения, ₽/)).toHaveValue(
      '1,03',
    )
    await page.getByRole('button', { name: 'Сохранить наблюдение' }).click()
    await page.getByRole('button', { name: 'Настроить 49761882' }).click()
    await page.getByLabel(/^Суточный лимит наблюдения, ₽/).fill('2,00')
    state.holdWrites()
    await page.getByRole('button', { name: 'Сохранить наблюдение' }).click()
    await expect(
      page.getByRole('button', { name: 'Настроить 49761882' }),
    ).toBeDisabled()
    await page
      .getByRole('tab', { name: 'История действий', exact: true })
      .click()
    await expect(
      page.getByText('Дождитесь результата сохранения.'),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Отбросить и перейти' }),
    ).toHaveCount(0)
    state.release()
    await expect(
      page.getByRole('button', { name: 'Настроить 49761882' }),
    ).toBeEnabled()
    expect(state.writes()).toBe(2)
    await page.getByRole('button', { name: 'Остаться', exact: true }).click()
    state.setConflict()
    await page.getByRole('button', { name: 'Настроить 49761882' }).click()
    await page.getByLabel(/^Суточный лимит наблюдения, ₽/).fill('3,00')
    await page.getByRole('button', { name: 'Сохранить наблюдение' }).click()
    await expect(page.getByRole('alert')).toContainText('актуальную версию')
    await expect(
      page.getByRole('button', { name: 'Сохранить наблюдение' }),
    ).toBeDisabled()
  })
  test('ошибка API и выключенный модуль видимы; узкий экран сохраняет вкладки', async ({
    page,
  }) => {
    const state = await fixture(page)
    await page.setViewportSize({ width: 700, height: 900 })
    await login(page)
    state.setListError()
    await page.getByRole('button', { name: 'Обновить', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText(
      'Не удалось подтвердить результат',
    )
    for (const name of [
      'Кампании',
      'Стратегии',
      'История действий',
      'Настройки',
    ])
      await expect(page.getByRole('tab', { name, exact: true })).toBeVisible()
    state.disable()
    await page.reload()
    await expect(
      page.getByText('Раздел наблюдения пока выключен.'),
    ).toBeVisible()
    await expect(page.getByRole('alert')).toContainText(
      'Не удалось подтвердить результат',
    )
    await expect(
      page.getByText('Тестовая CPC-кампания', { exact: true }),
    ).toHaveCount(0)
    await expect(
      page
        .getByRole('navigation')
        .getByRole('link', { name: 'Озон Ads', exact: true }),
    ).toHaveCount(1)
    const dataReads = () =>
      state.reads.filter((path) => /\/ozon-ads\/(policies|events)$/.test(path))
        .length
    const before = dataReads()
    await page.getByRole('link', { name: 'Главная', exact: true }).click()
    await page.getByRole('link', { name: 'Озон Ads', exact: true }).click()
    await expect(
      page.getByText('Раздел наблюдения пока выключен.'),
    ).toBeVisible()
    for (const name of ['Стратегии', 'История действий', 'Настройки']) {
      await page.getByRole('tab', { name, exact: true }).click()
      await expect(
        page.getByText('Раздел наблюдения пока выключен.'),
      ).toBeVisible()
    }
    await expect(
      page.getByRole('button', { name: 'Добавить наблюдение' }),
    ).toHaveCount(0)
    expect(dataReads()).toBe(before)
    expect(state.writes()).toBe(0)
  })
})
