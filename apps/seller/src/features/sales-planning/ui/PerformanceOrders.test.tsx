import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceOrders as MetricRows } from './PerformanceOrders'
import { performanceColumns } from '../../../../tests/fixtures/performanceColumns'
import type { components } from '../../../api/schema'

function PerformanceOrders({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  return <MetricRows columns={performanceColumns({ cohort: cohort ?? null })} />
}

it('shows effective orders and their evidence, not sales or unestimated quantities', () => {
  const cohort = performanceFixture.skus[0]?.points[0]?.period.cohort
  if (cohort === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceOrders cohort={cohort} />)
  expect(html).toContain('Заказано без отмен до передачи в доставку')
  expect(html).toContain('950')
  expect(html).toContain('Заказано товаров')
  expect(html).toContain('1000')
  expect(html).toContain('50')
  expect(html).toContain('Товары с неизвестным результатом заказа')
  expect(html).not.toContain('700')
  expect(html).not.toContain('20')
  const missing = renderToStaticMarkup(<PerformanceOrders cohort={null} />)
  expect(missing).toContain('Нет полных данных о заказах')
  expect(missing).not.toContain('>0<')
})
