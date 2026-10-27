import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { ApiError } from '../../../api/ApiError'
import { SalesPlanLifecycleError } from './SalesPlanLifecycleError'

it('shows occupied days rather than pretending that every conflict is a stale version', () => {
  const render = (code: string, message: string) =>
    renderToStaticMarkup(
      <SalesPlanLifecycleError
        error={new ApiError(409, code, message)}
        onDismiss={() => undefined}
        onVersionReviewed={() => undefined}
      />,
    )
  const days = render(
    'sales_plan_day_conflict',
    'Дни SKU уже заняты другим утверждённым планом.',
  )
  expect(days).toContain('Дни SKU уже заняты')
  expect(days).toContain('Измените период')
  expect(days).not.toContain('План изменился')
  expect(days).not.toContain('Я проверил обновлённый план')
  expect(render('version_conflict', 'Conflict')).toContain(
    'Я проверил обновлённый план',
  )
  expect(render('sales_plan_lineage_closed', 'Closed')).toContain(
    'Линия плана закрыта',
  )
  expect(render('sales_plan_not_draft', 'Status')).toContain(
    'не является черновиком',
  )
})
