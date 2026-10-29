import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceForecast as MetricRows } from './PerformanceForecast'
import { performanceColumns } from '../../../../tests/fixtures/performanceColumns'
import type { components } from '../../../api/schema'

function PerformanceForecast({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  return <MetricRows columns={performanceColumns({ cohort: cohort ?? null })} />
}

it('separates unknown outcome from unestimated quantity and explains the ten percent limit', () => {
  const c = performanceFixture.skus[0]?.points[0]?.period.cohort
  if (c === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceForecast cohort={c} />)
  expect(html).toContain('Прогноз выкупа')
  expect(html).toContain('>900<')
  expect(html).toContain('Без оценки прогноза')
  expect(html).toContain('>20<')
  expect(html).toContain('>2<')
  const suppressed = renderToStaticMarkup(
    <PerformanceForecast
      cohort={{
        ...c,
        unestimatedRateBps: 1001,
        currentProjectedBuyoutQuantity: null,
      }}
    />,
  )
  expect(suppressed).toContain('Больше 10% заказов без оценки')
  expect(suppressed).not.toContain('>900<')
  const boundary = renderToStaticMarkup(
    <PerformanceForecast cohort={{ ...c, unestimatedRateBps: 1000 }} />,
  )
  expect(boundary).toContain('>900<')
  const empty = renderToStaticMarkup(
    <PerformanceForecast
      cohort={{
        ...c,
        rawOrderedQuantity: 0,
        currentProjectedBuyoutQuantity: null,
        unestimatedRateBps: null,
      }}
    />,
  )
  expect(empty).toContain('Нет заказов для оценки')
})
