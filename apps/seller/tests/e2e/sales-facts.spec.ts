import { randomUUID } from 'node:crypto'
import { expect, test } from '@playwright/test'

// companyId и учётные данные сеются make test-e2e перед прогоном
// (bin/e2e-seed.sh) — реальный вход через форму (tracer bullet #2),
// единственное членство даёт автопереход мимо списка компаний
// (features/auth/ui/CompanyListPage.tsx, ТЗ §7.6).
const companyId = process.env.E2E_COMPANY_ID
const userEmail = process.env.E2E_USER_EMAIL
const userPassword = process.env.E2E_USER_PASSWORD

async function login(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Email').fill(userEmail ?? '')
  await page.getByLabel('Пароль').fill(userPassword ?? '')
  await page.getByRole('button', { name: 'Войти' }).click()
}

test.describe('sales facts', () => {
  test.beforeEach(() => {
    test.skip(
      companyId === undefined ||
        userEmail === undefined ||
        userPassword === undefined,
      'E2E_COMPANY_ID/E2E_USER_EMAIL/E2E_USER_PASSWORD не заданы — запускать через make test-e2e, он сеет данные и передаёт их',
    )
  })

  test('seller sees Ozon sales facts imported from a real fixture', async ({
    page,
  }) => {
    await login(page)

    // Один участник — автопереход мимо списка компаний прямо на экран продаж.
    await expect(page).toHaveURL(
      `/companies/${companyId}/ingestion/sales-facts`,
    )
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

  test('a foreign companyId is denied, not shown as an empty screen', async ({
    page,
  }) => {
    await login(page)
    await expect(page).toHaveURL(
      `/companies/${companyId}/ingestion/sales-facts`,
    )

    // Случайный UUID — заведомо не компания этого пользователя
    // (ТЗ, критерий приёмки 3: подстановка чужого companyId → отказ,
    // ни один ответ не содержит чужих данных).
    const foreignCompanyId = randomUUID()
    await page.goto(`/companies/${foreignCompanyId}/ingestion/sales-facts`)

    // 403 уводит со страницы — не "тихий пустой экран" (ТЗ §6): ни чужой
    // таблицы (даже пустой), ни зависания на URL чужой компании. Один
    // участник компании — редирект на /companies автопереходит обратно
    // на единственную свою.
    await expect(page).toHaveURL(
      `/companies/${companyId}/ingestion/sales-facts`,
    )
    await expect(
      page.getByRole('heading', { name: 'Продажи Ozon' }),
    ).toBeVisible()
  })
})
