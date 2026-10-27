import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceOrders } from './PerformanceOrders'

it('shows effective orders and their evidence, not sales or unestimated quantities', () => {
  const cohort = performanceFixture.skus[0]?.points[0]?.period.cohort
  if (cohort === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceOrders cohort={cohort} />)
  expect(html).toContain('Без отмен до передачи')
  expect(html).toContain('950')
  expect(html).toContain('Всего заказали')
  expect(html).toContain('1000')
  expect(html).toContain('50')
  expect(html).toContain('Нераспознанный исход')
  expect(html).not.toContain('700')
  expect(html).not.toContain('20')
  const missing = renderToStaticMarkup(<PerformanceOrders cohort={null} />)
  expect(missing).toContain('Нет полных данных о заказах')
  expect(missing).not.toContain('>0<')
})
