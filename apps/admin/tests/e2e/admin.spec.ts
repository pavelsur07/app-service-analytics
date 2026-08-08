import { expect, test } from '@playwright/test'

test('admin start screen shows data from its own endpoint', async ({
  page,
}) => {
  await page.goto('/')

  await expect(
    page.getByRole('heading', { name: 'Conwix — Admin' }),
  ).toBeVisible()

  // Экран размечен списком определений: подпись и значение — разные
  // узлы, поэтому проверяется пара, а не строка «app: …».
  const value = (term: string) =>
    page.getByRole('term').filter({ hasText: term }).locator('~ dd').first()

  await expect(value('app')).toHaveText('conwix-admin-api')
  await expect(value('version')).not.toBeEmpty()
  await expect(value('respondedAt')).not.toBeEmpty()
})
