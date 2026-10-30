import { useId, useState, type ComponentProps, type ReactNode } from 'react'
import { Badge, Button } from '../../../../../../packages/ui/src'

export function PerformanceStatus({
  status,
  label,
  tone = 'neutral',
  children,
}: {
  status: string
  label: string
  tone?: ComponentProps<typeof Badge>['tone']
  children: ReactNode
}) {
  const [expanded, setExpanded] = useState(false)
  const descriptionId = useId()
  return (
    <div className="max-w-full">
      <div className="flex flex-wrap items-center justify-end gap-1">
        <Badge size="compact" tone={tone}>
          {status}
        </Badge>
        <Button
          type="button"
          variant="ghost"
          size="compact"
          aria-label={`Пояснение: ${label}`}
          aria-expanded={expanded}
          aria-controls={descriptionId}
          onClick={() => setExpanded((value) => !value)}
        >
          <span aria-hidden="true">ⓘ</span>
        </Button>
      </div>
      <div
        id={descriptionId}
        hidden={!expanded}
        className="mt-2 text-left text-xs wrap-anywhere whitespace-normal text-text-muted"
      >
        {children}
      </div>
    </div>
  )
}
