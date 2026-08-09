import { expect, test } from '@playwright/test'

// companyId сеется make test-e2e перед прогоном (bin/e2e-seed.sh) —
// вход и выбор компании отложены ADR-007 (саморегистрация не готова),
// поэтому сквозной сценарий CLAUDE.md §10 («вход, выбор компании,
// просмотр, переключение») пока недостижим целиком: экран открывается
// по прямой ссылке компании, это и есть узкий случай tracer bullet.
const companyId = process.env.E2E_COMPANY_ID

test('seller sees Ozon sales facts imported from a real fixture', async ({
  page,
}) => {
  test.skip(
    companyId === undefined,
    'E2E_COMPANY_ID не задан — запускать через make test-e2e, он сеет данные и передаёт id',
  )

  await page.goto(`/companies/${companyId}/ingestion/sales-facts`)

  await expect(
    page.getByRole('heading', { name: 'Продажи Ozon' }),
  ).toBeVisible()

  // Первая запись реальной фикстуры пакета 0 (posting_number
  // 40705738-0407-1, sku 4404411581): отменена, комиссия 0, сумма
  // 2160.00 -> 2 160,00 ₽ — сверено до копейки вручную с фикстурой.
  const knownRow = page.locator('tbody tr').filter({ hasText: '4404411581' })
  await expect(knownRow).toBeVisible()
  await expect(knownRow).toContainText('✕ отменено')
  await expect(knownRow).toContainText('2 160,00 ₽')

  // 86 строк в фикстуре, лимит по умолчанию 50 — «Дальше» обязана быть
  // доступна, подтверждая, что курсорная пагинация работает не только
  // на игрушечном наборе из пары строк.
  await expect(page.getByRole('button', { name: 'Дальше' })).toBeEnabled()
})
