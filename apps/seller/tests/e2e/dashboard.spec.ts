import { expect, test } from '@playwright/test'

const companyId = process.env.E2E_COMPANY_ID
const secondCompanyId = process.env.E2E_SECOND_COMPANY_ID
const userEmail = process.env.E2E_USER_EMAIL
const userPassword = process.env.E2E_USER_PASSWORD

function twoDaysAgo(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Moscow' }).format(
    new Date(Date.now() - 2 * 86_400_000),
  )
}

test('прибыль: цена, исправление, периоды, видимость и изоляция компании', async ({
  page,
}) => {
  test.skip(
    companyId === undefined ||
      secondCompanyId === undefined ||
      userEmail === undefined ||
      userPassword === undefined,
    'Запускать через make test-e2e',
  )
  test.setTimeout(90_000)
  await page.goto('/login')
  await page.getByLabel('Email').fill(userEmail ?? '')
  await page.getByLabel('Пароль').fill(userPassword ?? '')
  await page.getByRole('button', { name: 'Войти', exact: true }).click()
  await page.getByRole('link', { name: 'E2E Sandbox LLC', exact: true }).click()
  await page.getByRole('button', { name: 'Вчера', exact: true }).click()
  const profit = page.getByRole('region', { name: 'Прибыль', exact: true })
  await expect(profit).toContainText('Не задана себестоимость для 1 шт.')
  await profit.getByRole('link', { name: 'Задать себестоимость' }).click()
  await page
    .getByRole('button', { name: 'Новая цена с даты', exact: true })
    .click()
  await page.getByLabel('Цена за штуку, ₽').fill('300')
  await page.getByLabel('Действует с').fill(twoDaysAgo())
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(
    page.getByRole('button', { name: 'Исправить действующую' }),
  ).toBeVisible()
  const nav = page.getByRole('navigation', { name: 'Разделы компании' })
  await nav.getByRole('link', { name: 'Главная', exact: true }).click()
  await expect(profit).toContainText('350 ₽')
  await expect(profit).toContainText('было 150 ₽')

  await page.getByRole('button', { name: '7 дней', exact: true }).click()
  await expect(profit).toContainText('прибыль недоступна')
  await page.getByRole('button', { name: 'Вчера', exact: true }).click()
  await expect(profit).toContainText('350 ₽')

  await profit.getByRole('button', { name: 'Скрыть виджет: Прибыль' }).click()
  await page.reload()
  await expect(profit).toHaveCount(0)
  await page.getByRole('button', { name: /Виджеты ·/ }).click()
  await page.getByRole('switch', { name: 'Прибыль', exact: true }).click()
  await page.keyboard.press('Escape')
  await expect(profit).toContainText('350 ₽')

  await nav.getByRole('link', { name: 'Себестоимость', exact: true }).click()
  await page.getByRole('button', { name: 'Исправить действующую' }).click()
  await page.getByLabel('Цена за штуку, ₽').fill('400')
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(page.getByLabel('Цена за штуку, ₽')).toHaveCount(0)
  await nav.getByRole('link', { name: 'Главная', exact: true }).click()
  await expect(profit).toContainText('250 ₽')
  await expect(profit).toContainText('Себестоимость периода исправлялась')

  await page
    .getByRole('banner')
    .getByRole('link', { name: 'E2E Sandbox LLC' })
    .click()
  await page.getByRole('link', { name: 'E2E Sandbox Two', exact: true }).click()
  await expect(page).toHaveURL(`/companies/${secondCompanyId}/dashboard`)
  await expect(profit).not.toContainText('250 ₽')
})
