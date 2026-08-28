import { randomUUID } from 'node:crypto'
import { expect, test } from '@playwright/test'

// Компании и учётные данные сеются make test-e2e перед прогоном
// (bin/e2e-seed.sh). Компаний две и участник в обеих, поэтому
// автоперехода мимо списка нет: §10 требует сквозного сценария
// с выбором и переключением компании, а при одной компании ни то,
// ни другое не выполнялось бы ни разу.
const companyId = process.env.E2E_COMPANY_ID
const secondCompanyId = process.env.E2E_SECOND_COMPANY_ID
const userEmail = process.env.E2E_USER_EMAIL
const userPassword = process.env.E2E_USER_PASSWORD

type Page = import('@playwright/test').Page

async function login(page: Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Email').fill(userEmail ?? '')
  await page.getByLabel('Пароль').fill(userPassword ?? '')
  await page.getByRole('button', { name: 'Войти' }).click()
}

/** Вход и выбор компании из списка — участник состоит в двух. */
async function loginAndOpen(page: Page, company: string): Promise<void> {
  await login(page)
  await expect(page).toHaveURL('/companies')
  await page.getByRole('link', { name: companyNameOf(company) }).click()
  await expect(page).toHaveURL(`/companies/${company}/sales`)
}

function companyNameOf(company: string): string {
  return company === companyId ? 'E2E Sandbox LLC' : 'E2E Sandbox Two'
}

test.describe('sales facts', () => {
  test.beforeEach(() => {
    test.skip(
      companyId === undefined ||
        secondCompanyId === undefined ||
        userEmail === undefined ||
        userPassword === undefined,
      'E2E_COMPANY_ID/E2E_SECOND_COMPANY_ID/E2E_USER_EMAIL/E2E_USER_PASSWORD не заданы — запускать через make test-e2e, он сеет данные и передаёт их',
    )
  })

  test('seller sees Ozon sales facts imported from a real fixture', async ({
    page,
  }) => {
    await loginAndOpen(page, companyId ?? '')

    await expect(
      page.getByRole('heading', { name: 'Продажи Ozon' }),
    ).toBeVisible()

    // Первая запись реальной фикстуры пакета 0 (posting_number
    // 40705738-0407-1, sku 4404411581): отменена, комиссия 0, сумма
    // 2160.00 -> 2 160,00 ₽ — сверено до копейки вручную с фикстурой.
    const knownRow = page.locator('tbody tr').filter({ hasText: '4404411581' })
    await expect(knownRow).toBeVisible()
    await expect(knownRow).toContainText('Отменено')
    await expect(knownRow).toContainText('2 160,00 ₽')

    // 86 строк в фикстуре, лимит по умолчанию 50 — «Дальше» обязана быть
    // доступна, подтверждая, что курсорная пагинация работает не только
    // на игрушечном наборе из пары строк.
    await expect(page.getByRole('button', { name: 'Дальше' })).toBeEnabled()
  })

  test('сайдбар переносит между разделами компании', async ({ page }) => {
    await loginAndOpen(page, companyId ?? '')

    const nav = page.getByRole('navigation', { name: 'Разделы компании' })

    // Ссылки в сайдбаре относительные и разрешаются от оболочки
    // /companies/:companyId. Абсолютный путь увёл бы в чужую компанию
    // или в никуда — молча, потому что экран отрисовался бы.
    await nav.getByRole('link', { name: 'Расширение' }).click()
    await expect(page).toHaveURL(`/companies/${companyId}/extension`)

    await nav.getByRole('link', { name: 'Продажи' }).click()
    await expect(page).toHaveURL(`/companies/${companyId}/sales`)

    // Выход доступен с любого экрана компании, а не только со списка:
    // до появления сайдбара выйти после входа было физически негде.
    await nav.getByRole('button', { name: 'Выйти' }).click()
    await expect(page).toHaveURL(/\/login$/)
  })

  test('переключение компании не показывает данные предыдущей', async ({
    page,
  }) => {
    // Сквозной сценарий §10: вход, выбор компании, просмотр данных,
    // переключение компании. Последние два шага до этого теста
    // не проходились ни разу — сид создавал одну компанию, и приложение
    // уводило мимо списка автопереходом.
    await loginAndOpen(page, companyId ?? '')
    await expect(page.locator('tbody tr').first()).toBeVisible()

    // Возврат к списку — через переключатель компании в топбаре
    // (docs/design/ui-kit/v0.6.html, раздел 10): в сайдбаре его больше нет.
    const topbar = page.getByRole('banner')
    await topbar.getByRole('link', { name: 'E2E Sandbox LLC' }).click()
    await expect(page).toHaveURL('/companies')

    await page.getByRole('link', { name: 'E2E Sandbox Two' }).click()
    await expect(page).toHaveURL(`/companies/${secondCompanyId}/sales`)

    // У второй компании продаж нет вовсе. Если бы ключ кэша не содержал
    // companyId (CLAUDE.md §7), здесь остались бы строки первой —
    // бэкенд при этом отработал бы правильно, и заметить было бы нечем.
    await expect(page.locator('tbody tr')).toHaveCount(0)
    await expect(
      topbar.getByRole('link', { name: 'E2E Sandbox Two' }),
    ).toBeVisible()
  })

  test('a foreign companyId is denied, not shown as an empty screen', async ({
    page,
  }) => {
    await loginAndOpen(page, companyId ?? '')

    // Случайный UUID — заведомо не компания этого пользователя
    // (ТЗ, критерий приёмки 3: подстановка чужого companyId → отказ,
    // ни один ответ не содержит чужих данных).
    const foreignCompanyId = randomUUID()
    await page.goto(`/companies/${foreignCompanyId}/sales`)

    // 403 уводит со страницы — не "тихий пустой экран" (ТЗ §6): ни чужой
    // таблицы (даже пустой), ни зависания на URL чужой компании.
    // Компаний у участника две, поэтому он остаётся на их списке,
    // а не проваливается автопереходом в одну.
    await expect(page).toHaveURL('/companies')
    await expect(
      page.getByRole('link', { name: 'E2E Sandbox LLC' }),
    ).toBeVisible()
    // Ни одной строки продаж: отказ не должен оставить на экране данные,
    // добытые до него.
    await expect(page.locator('tbody tr')).toHaveCount(0)
  })
})
