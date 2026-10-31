import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceBuyout as MetricRows } from './PerformanceBuyout'
import { performanceColumns } from '../../../../tests/fixtures/performanceColumns'
import type { components } from '../../../api/schema'

function PerformanceBuyout({
  cohort,
}: {
  cohort: components['schemas']['PerformanceCohortResponse'] | null | undefined
}) {
  return <MetricRows columns={performanceColumns({ cohort: cohort ?? null })} />
}

it('labels known, mature and projected buyout without converting them to sales', () => {
  const c = performanceFixture.skus[0]?.points[0]?.period.cohort
  if (c === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceBuyout cohort={c} />)
  expect(html).toContain('>87,5<')
  expect(html).toContain('>90<')
  expect(html).toContain('>Предварительный</span>')
  expect(html).toContain('aria-label="Пояснение: статус расчёта выкупа"')
  expect(html).toContain('aria-expanded="false"')
  expect(html).toContain('не означает завершение возвратов')
  expect(html).toContain('О показателе')
  const zero = renderToStaticMarkup(
    <PerformanceBuyout
      cohort={{
        ...c,
        knownBuyoutRateBps: null,
        actualBuyoutRateBps: null,
        currentProjectedBuyoutRateBps: null,
      }}
    />,
  )
  expect(zero).not.toContain('>0<')
})
