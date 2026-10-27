import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceGrid } from './PerformanceGrid'

it('renders real metrics inside a captioned keyboard-scrollable table', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={performanceFixture} />,
  )
  expect(html).toContain('<caption')
  expect(html).toContain('scope="col"')
  expect(html).toContain('scope="row"')
  expect(html).toContain('tabindex="0"')
  expect(html).toContain('SKU-1')
  expect(html).toContain('Исходная цель')
  expect(html).toContain('01.07–02.07')
  expect(html).toContain('700 шт.')
  expect(html).toContain('Только эта страница SKU')
})

it('switches to server cumulative rather than adding visible period values', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={performanceFixture} mode="cumulative" />,
  )
  expect(html).toContain('2000 шт.')
  expect(html).toContain('Нет полных данных о заказах')
  expect(html).not.toContain('700 шт.')
})
