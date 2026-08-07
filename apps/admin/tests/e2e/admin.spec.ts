import { expect, test } from '@playwright/test'

test('admin start screen shows data from its own endpoint', async ({
  page,
}) => {
  await page.goto('/')

  await expect(
    page.getByRole('heading', { name: 'Conwix — Admin' }),
  ).toBeVisible()
  await expect(page.getByText('app: conwix-admin-api')).toBeVisible()
  await expect(page.getByText(/version: .+/)).toBeVisible()
  await expect(page.getByText(/respondedAt: .+/)).toBeVisible()
})
