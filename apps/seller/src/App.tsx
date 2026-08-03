import { useEffect, useState } from 'react'
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
    // ponytail: plain fetch (via apiGet), not TanStack Query — one request,
    // no cache/retry needed yet. Query wiring is a later step.
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
  const state = usePing('/api/seller/ping')

  if (state.status === 'loading') {
    return <p>Загрузка…</p>
  }

  if (state.status === 'error') {
    return <p>Ошибка: {state.message}</p>
  }

  return (
    <div>
      <h1>Conwix — Seller</h1>
      <p>app: {state.info.app}</p>
      <p>version: {state.info.version}</p>
      <p>respondedAt: {state.info.respondedAt}</p>
    </div>
  )
}
