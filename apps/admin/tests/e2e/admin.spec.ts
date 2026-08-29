import { expect, test } from '@playwright/test'

const email = process.env.E2E_ADMIN_EMAIL
const password = process.env.E2E_ADMIN_PASSWORD

test.skip(
  !email || !password,
  'E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD задаёт bin/e2e-seed.sh через make test-e2e',
)

test('SuperAdmin входит и заводит Admin', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/')

  // Палитра всегда светлая, независимо от системной темы — проверка
  // переехала сюда с убранного пинг-экрана, она про оболочку, а не
  // про конкретный экран.
  await expect(page.locator('body')).toHaveCSS('color-scheme', 'light')

  // Корень уводит на вход, пока сессии нет.
  await expect(page).toHaveURL(/\/login$/)

  await page.getByLabel('Email').fill(email ?? '')
  await page.getByLabel('Пароль').fill(password ?? '')
  await page.getByRole('button', { name: 'Войти' }).click()

  await expect(page).toHaveURL(/\/administrators$/)
  // Роль видна: она объясняет, почему форма доступна.
  await expect(page.getByText('SuperAdmin')).toBeVisible()

  // Email уникален по индексу, поэтому у каждого прогона свой.
  const created = `e2e-ops-${Date.now()}@example.com`
  await page.getByLabel('Email').fill(created)
  await page.getByLabel('Пароль').fill('e2e-long-enough-password')
  await page.getByRole('button', { name: 'Завести' }).click()

  await expect(page.getByRole('status')).toContainText(created)

  // Выход возвращает на вход и не оставляет доступ к разделу.
  await page.getByRole('button', { name: 'Выйти' }).click()
  await expect(page).toHaveURL(/\/login$/)
})
