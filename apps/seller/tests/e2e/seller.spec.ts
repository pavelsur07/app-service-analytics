import { expect, test } from '@playwright/test'

test('seller start screen shows data from its own endpoint', async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/')

  await expect(page.locator('body')).toHaveCSS('color-scheme', 'light')
  await expect(page.locator('body')).toHaveCSS(
    'background-color',
    'rgb(244, 246, 250)',
  )
  await expect(page.locator('body')).toHaveCSS('color', 'rgb(11, 18, 32)')

  await expect(
    page.getByRole('heading', { name: 'Conwix — Seller' }),
  ).toBeVisible()

  // Экран размечен списком определений: подпись и значение — разные
  // узлы, поэтому проверяется пара, а не строка «app: …».
  const value = (term: string) =>
    page.getByRole('term').filter({ hasText: term }).locator('~ dd').first()

  await expect(value('app')).toHaveText('conwix-seller-api')
  await expect(value('version')).not.toBeEmpty()
  await expect(value('respondedAt')).not.toBeEmpty()
})
