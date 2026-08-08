import { useEffect, useState } from 'react'
import { Badge, Card } from '../../../packages/ui/src'
import { apiGet } from './api/client'
import type { components } from './api/schema'

type AppInfoResponse = components['schemas']['AppInfoResponse']

type PingState =
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'data'; info: AppInfoResponse }

function usePing(url: string): PingState {
  const [state, setState] = useState<PingState>({ status: 'loading' })

  useEffect(() => {
    // Голый fetch (через apiGet), не TanStack Query: один запрос,
    // кэш и повторы пока не нужны. Query подключается позже.
    let cancelled = false

    apiGet<AppInfoResponse>(url)
      .then((info) => {
        if (!cancelled) {
          setState({ status: 'data', info })
        }
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setState({
            status: 'error',
            message: error instanceof Error ? error.message : 'Unknown error',
          })
        }
      })

    return () => {
      cancelled = true
    }
  }, [url])

  return state
}

export function App() {
  const state = usePing('/api/admin/ping')

  if (state.status === 'loading') {
    return <p className="p-6 text-text-muted">Загрузка…</p>
  }

  if (state.status === 'error') {
    return (
      <div className="p-6">
        <Card>
          <div className="flex items-center gap-3">
            <Badge tone="negative">✕ ошибка</Badge>
            <span className="text-text-secondary">{state.message}</span>
          </div>
        </Card>
      </div>
    )
  }

  return (
    <div className="p-6">
      <Card>
        <div className="flex flex-col gap-4">
          <div className="flex items-center gap-3">
            <h1 className="text-xl font-semibold">Conwix — Admin</h1>
            <Badge tone="positive">✓ связь с API</Badge>
          </div>
          <dl className="flex flex-col gap-1 text-text-secondary">
            <div className="flex gap-4">
              <dt className="w-28">app</dt>
              <dd className="text-text-primary">{state.info.app}</dd>
            </div>
            <div className="flex gap-4">
              <dt className="w-28">version</dt>
              <dd className="text-text-primary">{state.info.version}</dd>
            </div>
            <div className="flex gap-4">
              <dt className="w-28">respondedAt</dt>
              <dd className="text-text-primary">{state.info.respondedAt}</dd>
            </div>
          </dl>
        </div>
      </Card>
    </div>
  )
}
