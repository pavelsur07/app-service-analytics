import { createHmac } from 'node:crypto'
import { Buffer } from 'node:buffer'
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
  await expect(page).toHaveURL(/\/mfa$/)
  const partial = await page.request.get('/api/admin/companies')
  expect(partial.status()).toBe(401)
  await page.getByRole('button', { name: 'Настроить второй фактор' }).click()
  const secret = await page.getByLabel('Секрет аутентификатора').inputValue()
  await page
    .getByLabel('Код аутентификатора', { exact: true })
    .fill(totpCode(secret))
  await page.getByRole('button', { name: 'Подтвердить', exact: true }).click()
  await expect(
    page.getByRole('heading', { name: 'Сохраните резервные коды' }),
  ).toBeVisible()
  const recoveryCodes = await page
    .getByRole('list', { name: 'Резервные коды' })
    .locator('code')
    .allTextContents()
  expect(recoveryCodes).toHaveLength(10)
  const confirmation = recoveryCodes[9]
  if (!confirmation) throw new Error('Missing synthetic confirmation code')
  await page.getByRole('button', { name: 'Коды сохранены, продолжить' }).click()
  await expect(page).toHaveURL(/\/accounts$/)
  await page.getByRole('link', { name: 'Безопасность', exact: true }).click()
  await page
    .getByLabel('Код аутентификатора или резервный код')
    .fill(confirmation)
  await page
    .getByRole('button', { name: 'Перевыпустить резервные коды' })
    .click()
  await expect(
    page.getByRole('heading', { name: 'Сохраните резервные коды' }),
  ).toBeVisible()
  const regenerated = await page
    .getByRole('list', { name: 'Резервные коды' })
    .locator('code')
    .allTextContents()
  expect(regenerated).toHaveLength(10)
  const recovery = regenerated[0]
  if (!recovery) throw new Error('Missing synthetic recovery code')
  expect(regenerated).not.toContain(confirmation)
  await page.getByRole('button', { name: 'Коды сохранены, продолжить' }).click()
  await expect(page).toHaveURL(/\/accounts$/)

  await page.context().route('http://evil.conwix.internal/**', (route) =>
    route.fulfill({
      contentType: 'text/html',
      body: '<title>Sibling origin</title>',
    }),
  )
  await page.goto('http://evil.conwix.internal/')
  const attacker = `csrf-${String(Date.now())}@example.test`
  const rejected = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/admin/administrators') &&
      response.request().method() === 'POST',
  )
  await page.evaluate((attackerEmail) => {
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = 'http://admin.conwix.internal/api/admin/administrators'
    form.enctype = 'text/plain'
    const input = document.createElement('input')
    input.name = JSON.stringify({
      email: attackerEmail,
      password: 'attacker-password-1234',
      padding: '',
    }).slice(0, -2)
    input.value = '"}'
    form.append(input)
    document.body.append(form)
    form.submit()
  }, attacker)
  expect((await rejected).status()).toBe(403)

  // 201 тем же email доказывает отсутствие строки после атаки и сохранение сессии.
  const legitimate = await page
    .context()
    .request.post('http://admin.conwix.internal/api/admin/administrators', {
      headers: {
        'X-Conwix-Request': '1',
        Origin: 'http://admin.conwix.internal',
      },
      data: { email: attacker, password: 'legitimate-password-1234' },
    })
  expect(legitimate.status()).toBe(201)
  await page.goto('/accounts')
  await expect(
    page.getByRole('navigation', { name: 'Разделы администрирования' }),
  ).toBeVisible()
  await expect(page.getByRole('banner')).toContainText('Администрирование')
  await expect(page.getByRole('link', { name: 'Аккаунты' })).toHaveCSS(
    'background-color',
    'rgb(238, 243, 254)',
  )
  // Роль видна: она объясняет, какие пункты меню доступны.
  await expect(page.getByText('SuperAdmin')).toBeVisible()

  // Регистрация аккаунта: компания и владелец одним действием.
  const stamp = Date.now()
  const companyName = `E2E Клиент ${String(stamp)}`
  const newAccountButton = page.getByRole('button', { name: 'Новый аккаунт' })
  const accountDialog = page.getByRole('dialog', { name: 'Новый аккаунт' })
  await expect(page.getByLabel('Название компании')).toHaveCount(0)
  await newAccountButton.click()
  await expect(accountDialog).toBeVisible()
  await expect(accountDialog.getByLabel('Название компании')).toBeFocused()
  await accountDialog.evaluate((dialog: HTMLDialogElement) => {
    dialog.close()
  })
  await expect(accountDialog).toHaveCount(0)
  await expect(newAccountButton).toBeFocused()
  await newAccountButton.click()
  await page.keyboard.press('Escape')
  await expect(accountDialog).toHaveCount(0)
  await expect(newAccountButton).toBeFocused()
  await newAccountButton.click()
  await accountDialog.getByRole('button', { name: 'Зарегистрировать' }).click()
  await expect(accountDialog.getByText('Введите название')).toBeVisible()
  await accountDialog.getByLabel('Название компании').fill(companyName)
  await accountDialog
    .getByLabel('Email владельца')
    .fill(`e2e-owner-${String(stamp)}@example.com`)
  await accountDialog
    .getByLabel('Пароль владельца')
    .fill('e2e-long-enough-password')
  await page.route(
    '**/api/admin/companies',
    async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 200))
      await route.fulfill({ status: 503, body: '{}' })
    },
    { times: 1 },
  )
  await accountDialog.getByRole('button', { name: 'Зарегистрировать' }).click()
  await expect(
    accountDialog.getByRole('button', { name: 'Зарегистрировать' }),
  ).toBeDisabled()
  await accountDialog.evaluate((dialog: HTMLDialogElement) => {
    dialog.close()
  })
  await expect(accountDialog).toBeVisible()
  await expect(accountDialog.getByRole('alert')).toBeVisible()
  await accountDialog.getByRole('button', { name: 'Зарегистрировать' }).click()
  await expect(accountDialog).toHaveCount(0)
  await expect(newAccountButton).toBeFocused()

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

  // Ссылки доступны обеим административным ролям. В одном сценарии
  // проверяем полный рабочий цикл: создать, выбрать статистику,
  // исправить и временно отключить.
  await page.getByRole('link', { name: 'Ссылки' }).click()
  await expect(page).toHaveURL(/\/links$/)

  const campaignName = `E2E Campaign ${String(stamp)}`
  const campaignNameUpdated = `${campaignName} updated`
  const newLinkButton = page.getByRole('button', { name: 'Новая ссылка' })
  const createDialog = page.getByRole('dialog', { name: 'Новая ссылка' })
  await expect(page.getByLabel('Название ссылки', { exact: true })).toHaveCount(
    0,
  )
  await expect(page.getByText('Редактирование', { exact: true })).toHaveCount(0)
  await newLinkButton.click()
  await expect(createDialog).toBeVisible()
  await expect(createDialog.getByLabel('Название ссылки')).toBeFocused()
  await createDialog.getByLabel('Название ссылки').fill('Черновик')
  await page.keyboard.press('Escape')
  await expect(createDialog).toHaveCount(0)
  await expect(newLinkButton).toBeFocused()
  await newLinkButton.click()
  await expect(createDialog.getByLabel('Название ссылки')).toHaveValue('')
  await createDialog.getByRole('button', { name: 'Закрыть' }).click()
  await expect(createDialog).toHaveCount(0)
  await newLinkButton.click()
  await createDialog.getByRole('button', { name: 'Отмена' }).click()
  await expect(createDialog).toHaveCount(0)
  await newLinkButton.click()
  await createDialog.getByRole('button', { name: 'Создать ссылку' }).click()
  await expect(createDialog.getByText('Введите название')).toBeVisible()
  await expect(createDialog.getByText('Введите адрес назначения')).toBeVisible()
  await createDialog.getByLabel('Название ссылки').fill(campaignName)
  await createDialog
    .getByLabel('Адрес назначения')
    .fill('https://example.com/e2e-campaign')
  const createLinkResponse = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/admin/links') &&
      response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: 'Создать ссылку' }).click()
  const createdLink: unknown = await (await createLinkResponse).json()
  if (!isRecord(createdLink) || typeof createdLink.id !== 'string') {
    throw new Error('Ответ создания ссылки не содержит id.')
  }

  const linkRow = page.getByRole('row').filter({ hasText: campaignName })
  await expect(linkRow).toBeVisible()
  await expect(createDialog).toHaveCount(0)

  // Другая вкладка успела изменить ссылку: первый клик из
  // устаревшего UI получит 409, после чего строка сама подтянет
  // свежую version и повторное действие сработает без reload.
  const externalDisable = await page.request.post(
    `/api/admin/links/${encodeURIComponent(createdLink.id)}/status`,
    {
      headers: { 'X-Conwix-Request': '1' },
      data: { status: 'disabled', version: 1 },
    },
  )
  expect(externalDisable.ok()).toBe(true)
  await linkRow.getByRole('button', { name: 'Отключить' }).click()
  await expect(linkRow).toContainText('отключена')
  await expect(linkRow.getByRole('alert')).toHaveCount(0)
  await linkRow.getByRole('button', { name: 'Включить' }).click()
  await expect(linkRow).toContainText('работает')

  await linkRow.getByRole('button', { name: 'Показать переходы' }).click()

  const today = new Date().toISOString().slice(0, 10)
  const todayRow = page.getByRole('row').filter({ hasText: today })
  await expect(todayRow.getByRole('cell').last()).toHaveText('0')

  const code = (await linkRow.locator('code').textContent()) ?? ''
  expect(code).toMatch(/^[0-9A-Za-z]{7}$/)
  const redirect = await page.request.get(
    `http://lin.conwix.internal/${code}`,
    {
      headers: {
        'User-Agent': 'Mozilla/5.0 Chrome/130.0 Safari/537.36',
      },
      maxRedirects: 0,
    },
  )
  expect(redirect.status()).toBe(302)

  // Переход записан вне React-приложения, поэтому перечитываем экран:
  // статистика должна прийти из PostgreSQL, а не из клиентского кэша.
  await page.reload()
  await expect(todayRow.getByRole('cell').last()).toHaveText('1')

  const editButton = linkRow.getByRole('button', { name: 'Редактировать' })
  const editDialog = page.getByRole('dialog', { name: 'Изменить ссылку' })
  await expect(editDialog).toHaveCount(0)
  await editButton.click()
  await expect(editDialog).toBeVisible()
  await expect(
    editDialog.getByLabel('Название ссылки для изменения'),
  ).toHaveValue(campaignName)
  await expect(
    editDialog.getByLabel('Адрес назначения для изменения'),
  ).toHaveValue('https://example.com/e2e-campaign')
  await editDialog
    .getByLabel('Название ссылки для изменения')
    .fill('Не сохранять')
  await editDialog.getByRole('button', { name: 'Отмена' }).click()
  await expect(editDialog).toHaveCount(0)
  await expect(editButton).toBeFocused()
  await expect(linkRow).toContainText(campaignName)
  await editButton.click()
  await expect(
    editDialog.getByLabel('Название ссылки для изменения'),
  ).toHaveValue(campaignName)
  await page.keyboard.press('Escape')
  await expect(editDialog).toHaveCount(0)
  await editButton.click()
  await editDialog.getByRole('button', { name: 'Закрыть' }).click()
  await expect(editDialog).toHaveCount(0)
  await editButton.click()
  await editDialog
    .getByLabel('Название ссылки для изменения')
    .fill(campaignNameUpdated)
  await page.getByRole('button', { name: 'Сохранить изменения' }).click()
  await expect(linkRow).toContainText(campaignNameUpdated)
  await expect(editDialog).toHaveCount(0)

  await linkRow.getByRole('button', { name: 'Отключить' }).click()
  await expect(linkRow).toContainText('отключена')
  await linkRow.getByRole('button', { name: 'Включить' }).click()
  await expect(linkRow).toContainText('работает')

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

  // Восстановление по одноразовому коду и отказ его повторного применения.
  for (const attempt of [0, 1]) {
    await page.getByLabel('Email', { exact: true }).fill(email ?? '')
    await page.getByLabel('Пароль', { exact: true }).fill(password ?? '')
    await page.getByRole('button', { name: 'Войти' }).click()
    await expect(page).toHaveURL(/\/mfa$/)
    await page
      .getByLabel('Код аутентификатора или резервный код')
      .fill(recovery)
    await page.getByRole('button', { name: 'Подтвердить', exact: true }).click()
    if (attempt === 1) break
    await expect(page).toHaveURL(/\/accounts$/)
    await page.getByRole('button', { name: 'Выйти' }).click()
    await expect(page).toHaveURL(/\/login$/)
  }
  await expect(page.getByRole('alert')).toContainText('Код неверен')
  const stillPartial = await page.request.get('/api/admin/companies')
  expect(stillPartial.status()).toBe(401)
  await page.getByRole('button', { name: 'Выйти' }).click()
  await expect(page).toHaveURL(/\/login$/)
})

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function totpCode(secret: string): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = 0
  let buffer = 0
  const bytes: number[] = []
  for (const character of secret.replace(/=+$/, '')) {
    const index = alphabet.indexOf(character)
    if (index < 0) throw new Error('Invalid synthetic TOTP secret')
    buffer = (buffer << 5) | index
    bits += 5
    if (bits >= 8) {
      bits -= 8
      bytes.push((buffer >> bits) & 255)
    }
  }
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)))
  const digest = createHmac('sha1', Buffer.from(bytes)).update(counter).digest()
  const offset = (digest[digest.length - 1] ?? 0) & 15
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(
    6,
    '0',
  )
}
