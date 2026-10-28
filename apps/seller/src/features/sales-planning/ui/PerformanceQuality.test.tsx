import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { PerformanceQuality } from './PerformanceQuality'

it('distinguishes complete zeros from missing and partial and explains current day', () => {
  expect(
    renderToStaticMarkup(
      <PerformanceQuality coverage="complete" current={false} />,
    ),
  ).toContain('Заказы загружены полностью')
  expect(
    renderToStaticMarkup(
      <PerformanceQuality coverage="missing" current={false} />,
    ),
  ).toContain('Заказы не загружены')
  expect(
    renderToStaticMarkup(
      <PerformanceQuality coverage="partial" current={true} />,
    ),
  ).toContain('Текущий день ещё не завершён')
})
