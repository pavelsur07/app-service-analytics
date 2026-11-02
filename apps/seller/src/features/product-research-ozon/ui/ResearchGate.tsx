import type { ReactNode } from 'react'
import { CirclePause } from 'lucide-react'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { useAvailability } from '../model/queries'
import { ResearchError } from './ResearchError'

export function ResearchGate({
  companyId,
  children,
}: {
  companyId: string
  children: ReactNode
}) {
  const availability = useAvailability(companyId)
  if (availability.isPending)
    return <p role="status">Проверяем доступность подбора товаров…</p>
  if (availability.isError)
    return (
      <ResearchError
        error={availability.error}
        retry={() => {
          void availability.refetch()
        }}
      />
    )
  if (!availability.data.enabled)
    return (
      <Card>
        <StatusPanel
          title="Подбор товаров Ozon пока недоступен"
          description="Модуль ещё не включён для работы. Сохранённые проекты останутся доступны после включения."
          icon={<CirclePause size={20} aria-hidden="true" />}
          tone="neutral"
          action={
            <Button
              type="button"
              variant="secondary"
              size="compact"
              onClick={() => {
                void availability.refetch()
              }}
            >
              Проверить доступность
            </Button>
          }
        />
      </Card>
    )
  return children
}
