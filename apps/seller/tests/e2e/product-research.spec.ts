import { expect, test, type Page } from '@playwright/test'
import { researchFixture } from './product-research.fixture'

test.use({ actionTimeout: 15_000 })

const company = process.env.E2E_COMPANY_ID
const second = process.env.E2E_SECOND_COMPANY_ID
const email = process.env.E2E_USER_EMAIL
const password = process.env.E2E_USER_PASSWORD

async function openResearch(page: Page) {
  await page.clock.setFixedTime(new Date('2026-04-15T09:00:00Z'))
  await page.goto('/login')
  await page.getByLabel('Email', { exact: true }).fill(email ?? '')
  await page.getByLabel('Пароль', { exact: true }).fill(password ?? '')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await page.getByRole('link', { name: 'E2E Sandbox LLC', exact: true }).click()
  await page
    .getByRole('navigation', { name: 'Разделы компании' })
    .getByRole('link', { name: 'Подбор товаров Ozon' })
    .click()
  await expect(
    page.getByRole('heading', { name: 'Подбор товаров Ozon' }),
  ).toBeVisible()
}
async function fillResearch(page: Page) {
  await page.getByRole('button', { name: 'Новый проект', exact: true }).click()
  const category = page.getByRole('combobox', { name: 'Категория Ozon' })
  await category.fill('хи')
  await expect(page.getByRole('listbox')).toHaveCount(0)
  await expect(
    page.getByRole('button', { name: 'Создать проект', exact: true }),
  ).toBeDisabled()
  await category.fill('хид')
  await page.getByRole('option', { name: /Хиджабы и тюрбаны женские/ }).click()
  await category.fill('Произвольная категория')
  await expect(
    page.getByRole('button', { name: 'Создать проект', exact: true }),
  ).toBeDisabled()
  await category.fill('хид')
  await page.getByRole('option', { name: /Хиджабы и тюрбаны женские/ }).click()
  await page
    .getByLabel(/^Название проекта/)
    .fill('Исследование весеннего сезона')
  await expect(page.getByText(/Будет загружено 3 мес/)).toBeVisible()
}
async function createResearch(page: Page) {
  await fillResearch(page)
  await page
    .getByRole('button', { name: 'Создать проект', exact: true })
    .evaluate((button) => {
      if (button instanceof HTMLButtonElement) {
        button.click()
        button.click()
      }
    })
  await expect(
    page.getByRole('heading', {
      name: 'Исследование весеннего сезона',
      exact: true,
    }),
  ).toBeVisible()
  await expect(page.getByText('Черновик', { exact: true })).toBeVisible()
}

test.describe('product research Ozon — UI с синтетическим исследовательским источником', () => {
  test.beforeEach(() => {
    test.skip(
      company === undefined ||
        second === undefined ||
        email === undefined ||
        password === undefined,
      'Запускать через make test-e2e: нужны sandbox company/user',
    )
  })

  test('создать, загрузить, вернуть проект, добавить период, скопировать и сменить компанию', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const fixture = await researchFixture(page, company ?? '')
    await openResearch(page)
    await createResearch(page)
    const uri = page.url()
    fixture.hold()
    await page.getByRole('button', { name: 'Загрузить', exact: true }).click()
    await expect(page.getByText('Загрузка', { exact: true })).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Добавить периоды', exact: true }),
    ).toBeEnabled()
    expect(fixture.starts()).toBe(1)
    await expect(page.getByRole('combobox')).toHaveCount(0)
    await page
      .getByRole('button', { name: 'Добавить периоды', exact: true })
      .click()
    await page.getByLabel('Период 1: с', { exact: true }).fill('2025-01')
    await page.getByLabel('Период 1: по', { exact: true }).fill('2025-03')
    await page
      .getByLabel('Название периода 1', { exact: true })
      .fill('Весенний сезон')
    await expect(page.getByText(/В проекте будет 6 мес/)).toBeVisible()
    await page
      .getByRole('button', { name: 'Сохранить периоды', exact: true })
      .click()
    await expect(page.getByText('Загрузка', { exact: true })).toBeVisible()
    fixture.release()
    await page
      .getByRole('button', { name: 'Проверить прогресс', exact: true })
      .click()
    await expect(
      page.getByText('Загружено частично', { exact: true }),
    ).toBeVisible()
    await page
      .getByRole('button', {
        name: 'Дозагрузить неудачные и новые месяцы',
        exact: true,
      })
      .click()
    await expect(page.getByText('Готово 6 из 6 мес.')).toBeVisible({
      timeout: 20_000,
    })
    expect(fixture.runMonths()).toBe(3)
    await page.reload()
    await expect(page.getByText('Готово 6 из 6 мес.')).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Загрузить', exact: true }),
    ).toBeDisabled()
    await expect(
      page.getByText(/В этом месяце нет товаров/).first(),
    ).toBeVisible()
    await page.setViewportSize({ width: 800, height: 900 })
    await page.screenshot({
      path: 'var/product-research-ozon-stage7/project-narrow.png',
      fullPage: true,
    })
    await page
      .getByRole('button', { name: 'Скопировать проект', exact: true })
      .click()
    await expect(
      page.getByRole('combobox', { name: 'Категория Ozon' }),
    ).toHaveValue(/Хиджабы/)
    await page
      .getByRole('button', { name: 'Создать копию', exact: true })
      .click()
    await expect(
      page.getByRole('heading', { name: 'Копия исследования', exact: true }),
    ).toBeVisible()
    await expect(page.getByText('Черновик', { exact: true })).toBeVisible()
    expect(page.url()).not.toBe(uri)
    fixture.enableOther()
    await page.goto(`/companies/${second}/product-research`)
    await expect(
      page.getByText('Исследований пока нет', { exact: true }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Копия исследования', exact: true }),
    ).toHaveCount(0)
    expect(fixture.foreignReads()).toBe(1)
    fixture.disableOther()
    await page.reload()
    await expect(
      page.getByText('Подбор товаров Ozon пока недоступен', { exact: true }),
    ).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'Копия исследования', exact: true }),
    ).toHaveCount(0)
    await expect(
      page
        .getByRole('navigation', { name: 'Разделы компании' })
        .getByRole('link', { name: 'Подбор товаров Ozon' }),
    ).toHaveCount(0)
    expect(fixture.foreignReads()).toBe(1)
    expect(fixture.creates()).toBe(1)
  })

  test('частичный сбой и потеря ответа повторяются тем же intent, terminal останавливает polling', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const fixture = await researchFixture(page, company ?? '', true)
    await openResearch(page)
    await createResearch(page)
    await page.getByRole('button', { name: 'Загрузить', exact: true }).click()
    await expect(
      page.getByText('Загружено частично', { exact: true }),
    ).toBeVisible({ timeout: 20_000 })
    await expect(page.getByText(/Проверьте токен MPStats/)).toBeVisible()
    const reads = fixture.reads()
    // UI receives terminal state; waiting for the next poll window must add no reads.
    await page.waitForTimeout(5500)
    expect(fixture.reads()).toBe(reads)
    await page
      .getByRole('button', {
        name: 'Дозагрузить неудачные и новые месяцы',
        exact: true,
      })
      .click()
    await expect(
      page.getByRole('button', { name: 'Подтвердить запуск', exact: true }),
    ).toBeEnabled()
    expect(fixture.runMonths()).toBe(1)
    await page
      .getByRole('button', { name: 'Подтвердить запуск', exact: true })
      .click()
    await expect(page.getByText('Готово 3 из 3 мес.')).toBeVisible({
      timeout: 20_000,
    })
    expect(fixture.starts()).toBe(3)
    await expect(page.getByText('Кэш', { exact: true })).toBeVisible()
    await page.screenshot({
      path: 'var/product-research-ozon-stage7/project-ready.png',
      fullPage: true,
    })
  })
  test('потеря ответа создания не выдаёт успех и блокирует повторное создание', async ({
    page,
  }) => {
    test.setTimeout(90_000)
    const fixture = await researchFixture(page, company ?? '')
    fixture.loseCreateReply()
    await openResearch(page)
    await fillResearch(page)
    await page
      .getByRole('button', { name: 'Создать проект', exact: true })
      .click()
    await expect(
      page.getByText(/Результат сохранения не подтверждён/),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Создать проект', exact: true }),
    ).toBeDisabled()
    await expect(page.getByText('Черновик', { exact: true })).toHaveCount(0)
    expect(fixture.creates()).toBe(1)
    await page
      .getByRole('button', { name: 'Открыть список проектов', exact: true })
      .click()
    await expect(
      page.getByRole('heading', { name: 'Подбор товаров Ozon', exact: true }),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Создать проект', exact: true }),
    ).toHaveCount(0)
    await page
      .getByRole('link', { name: 'Исследование весеннего сезона', exact: true })
      .click()
    await expect(page.getByText('Черновик', { exact: true })).toBeVisible()
    expect(fixture.creates()).toBe(1)
  })
})
