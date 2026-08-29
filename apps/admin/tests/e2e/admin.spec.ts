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

  // exact: подписи «Email» и «Пароль» подстрокой совпадают с «Email
  // владельца» и «Пароль владельца» на экране аккаунтов. Пока страницы
  // сменяются, локатор без exact успевает взять поле уходящей — так
  // и падало в CI, где навигация медленнее.
  await page.getByLabel('Email', { exact: true }).fill(email ?? '')
  await page.getByLabel('Пароль', { exact: true }).fill(password ?? '')
  await page.getByRole('button', { name: 'Войти' }).click()

  // Вход ведёт на аккаунты — повседневную работу контура.
  await expect(page).toHaveURL(/\/accounts$/)
  // Роль видна: она объясняет, какие пункты меню доступны.
  await expect(page.getByText('SuperAdmin')).toBeVisible()

  // Регистрация аккаунта: компания и владелец одним действием.
  const stamp = Date.now()
  const companyName = `E2E Клиент ${String(stamp)}`
  await page.getByLabel('Название компании').fill(companyName)
  await page
    .getByLabel('Email владельца')
    .fill(`e2e-owner-${String(stamp)}@example.com`)
  await page.getByLabel('Пароль владельца').fill('e2e-long-enough-password')
  await page.getByRole('button', { name: 'Зарегистрировать' }).click()

  await expect(page.getByRole('status')).toContainText(companyName)

  // Новый аккаунт появился в списке и работает.
  const row = page.getByRole('row').filter({ hasText: companyName })
  await expect(row).toBeVisible()
  await expect(row).toContainText('работает')

  // Блокировка меняет состояние прямо в списке.
  await row.getByRole('button', { name: 'Заблокировать' }).click()
  await expect(row).toContainText('заблокирован')

  // И возвращается обратно — переход обратим.
  await row.getByRole('button', { name: 'Включить' }).click()
  await expect(row).toContainText('работает')

  // Заведение администратора — отдельный экран верхней роли.
  await page.getByRole('link', { name: 'Администраторы' }).click()
  await expect(page).toHaveURL(/\/administrators$/)
  // Адрес меняется раньше, чем перерисовывается страница: ждём саму
  // форму, иначе поля заполняются в ещё смонтированный прежний экран.
  await expect(
    page.getByRole('heading', { name: 'Новый администратор' }),
  ).toBeVisible()

  const createdAdmin = `e2e-ops-${String(stamp)}@example.com`
  await page.getByLabel('Email', { exact: true }).fill(createdAdmin)
  await page
    .getByLabel('Пароль', { exact: true })
    .fill('e2e-long-enough-password')
  await page.getByRole('button', { name: 'Завести' }).click()

  await expect(page.getByRole('status')).toContainText(createdAdmin)

  // Выход возвращает на вход и не оставляет доступ к разделу.
  await page.getByRole('button', { name: 'Выйти' }).click()
  await expect(page).toHaveURL(/\/login$/)
})
