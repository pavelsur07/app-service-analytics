import { expect, test } from '@playwright/test'

test('корень ведёт в приложение, палитра всегда светлая', async ({ page }) => {
  // Тёмная системная тема не должна ничего менять: интерфейс всегда
  // использует светлую палитру (docs/patterns.md, «Дизайн-система»).
  await page.emulateMedia({ colorScheme: 'dark' })
  await page.goto('/')

  await expect(page.locator('body')).toHaveCSS('color-scheme', 'light')
  await expect(page.locator('body')).toHaveCSS(
    'background-color',
    'rgb(244, 246, 250)',
  )
  await expect(page.locator('body')).toHaveCSS('color', 'rgb(11, 18, 32)')

  // Корень больше не отдельный экран, а редирект: без сессии цепочка
  // / → /companies → /login приводит на вход. Раньше здесь жил
  // ping-экран, единственной ценностью которого была первая полоска
  // насквозь.
  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByRole('button', { name: 'Войти' })).toBeVisible()
})
