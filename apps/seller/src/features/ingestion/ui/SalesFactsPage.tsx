import { useState } from 'react'
import { useParams } from 'react-router'
import { Badge, Button, Card } from '../../../../../../packages/ui/src'
import { useSalesFacts } from '../model/useSalesFacts'
import { SalesFactsTable } from './SalesFactsTable'

// Без переключения компании (нет второй компании в интерфейсе до входа) —
// companyId только из URL, источник правды (docs/patterns.md).
export function SalesFactsPage() {
  const { companyId } = useParams<{ companyId: string }>()
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null])
  const cursor = cursorStack[cursorStack.length - 1] ?? null

  const query = useSalesFacts(companyId ?? '', cursor, {
    enabled: companyId !== undefined,
  })

  if (companyId === undefined) {
    return (
      <div className="p-6">
        <Card>
          <div className="flex items-center gap-3">
            <Badge tone="negative">✕ ошибка</Badge>
            <span className="text-text-secondary">
              В адресе не указан companyId.
            </span>
          </div>
        </Card>
      </div>
    )
  }

  const nextCursor = query.data?.nextCursor ?? null

  return (
    <div className="p-6">
      <Card>
        <div className="flex flex-col gap-4">
          <h1 className="text-xl font-semibold">Продажи Ozon</h1>

          {query.status === 'pending' && (
            <p className="text-text-muted">Загрузка…</p>
          )}

          {query.status === 'error' && (
            <div className="flex items-center gap-3">
              <Badge tone="negative">✕ ошибка</Badge>
              <span className="text-text-secondary">
                {query.error instanceof Error
                  ? query.error.message
                  : 'Неизвестная ошибка'}
              </span>
            </div>
          )}

          {query.status === 'success' && (
            <>
              <SalesFactsTable items={query.data.items} />
              <div className="flex items-center gap-3">
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={cursorStack.length <= 1}
                  onClick={() => {
                    setCursorStack((stack) =>
                      stack.length > 1 ? stack.slice(0, -1) : stack,
                    )
                  }}
                >
                  Назад
                </Button>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={nextCursor === null}
                  onClick={() => {
                    if (nextCursor !== null) {
                      setCursorStack((stack) => [...stack, nextCursor])
                    }
                  }}
                >
                  Дальше
                </Button>
              </div>
            </>
          )}
        </div>
      </Card>
    </div>
  )
}
