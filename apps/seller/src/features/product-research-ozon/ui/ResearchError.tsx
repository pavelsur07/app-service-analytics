import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { CircleAlert } from 'lucide-react'
import { Link, useParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { errorText } from '../lib/research'
import { researchKey } from '../model/queries'

export function ResearchError({
  error,
  retry,
}: {
  error: unknown
  retry?: () => void
}) {
  const { companyId } = useParams<{ companyId: string }>()
  const queryClient = useQueryClient()
  useEffect(() => {
    if (
      companyId !== undefined &&
      error instanceof ApiError &&
      error.code === 'feature_disabled'
    ) {
      void queryClient.invalidateQueries({
        queryKey: researchKey(companyId, 'availability'),
      })
    }
  }, [companyId, error, queryClient])
  return (
    <Card tone="negative">
      <StatusPanel
        title="Не удалось выполнить действие"
        description={errorText(error)}
        tone="negative"
        role="alert"
        icon={<CircleAlert size={20} aria-hidden="true" />}
        action={
          <div className="flex gap-2">
            {retry !== undefined && (
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={retry}
              >
                Повторить
              </Button>
            )}
            {error instanceof ApiError && error.status === 403 && (
              <Link to="/companies">Выбрать компанию</Link>
            )}
            {error instanceof ApiError && error.status === 401 && (
              <Link to="/login">Войти</Link>
            )}
          </div>
        }
      />
    </Card>
  )
}
