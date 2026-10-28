import { Button } from '../../../../../../packages/ui/src'
import { lifecycleFailure } from '../lib/lifecycleFailure'

export function SalesPlanLifecycleError({
  error,
  onDismiss,
  onVersionReviewed,
}: {
  error: unknown
  onDismiss: () => void
  onVersionReviewed: () => void
}) {
  const failure = lifecycleFailure(error)
  return (
    <div className="mt-3 text-sm text-negative-text" role="alert">
      <p>{failure.message}</p>
      <Button
        size="compact"
        variant="secondary"
        onClick={failure.versionConflict ? onVersionReviewed : onDismiss}
      >
        {failure.versionConflict
          ? 'Я проверил обновлённый план'
          : 'Закрыть сообщение'}
      </Button>
    </div>
  )
}
