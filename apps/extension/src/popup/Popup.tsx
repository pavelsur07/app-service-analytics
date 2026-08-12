import { useEffect, useState } from 'react'

import { Button, Card } from '../../../../packages/ui/src'

import { fetchMe, isUnauthorized, API_BASE_URL } from '../api/client'
import {
  browserStorage,
  clearConnection,
  readConnection,
  type Connection,
} from '../shared/connection'

// Четыре состояния, и «проверяем» — отдельное от них: пока сеть
// не ответила, показывать «не подключено» нельзя, иначе клиент нажмёт
// «подключить» поверх живого подключения.
type State =
  | { readonly kind: 'checking' }
  | { readonly kind: 'disconnected' }
  | { readonly kind: 'connected'; readonly connection: Connection }
  | { readonly kind: 'expired' }
  | { readonly kind: 'unreachable'; readonly connection: Connection }

export function Popup(): React.JSX.Element {
  const [state, setState] = useState<State>({ kind: 'checking' })

  useEffect(() => {
    let cancelled = false

    void check().then((next) => {
      if (!cancelled) {
        setState(next)
      }
    })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <Card>
      <Body
        state={state}
        onDisconnect={() => void disconnect().then(setState)}
      />
    </Card>
  )
}

function Body({
  state,
  onDisconnect,
}: {
  state: State
  onDisconnect: () => void
}): React.JSX.Element {
  switch (state.kind) {
    case 'checking':
      return <p>Проверяем подключение…</p>

    case 'connected':
      return (
        <>
          <p>Подключено к компании</p>
          <p>
            <strong>{state.connection.companyName}</strong>
          </p>
          <Button variant="secondary" onClick={onDisconnect}>
            Отключить
          </Button>
        </>
      )

    // Истёк, отозван, участника исключили — снаружи неотличимы
    // намеренно (ADR-010), и действие клиента во всех трёх одно.
    case 'expired':
      return (
        <>
          <p>Подключение больше не действует.</p>
          <ConnectHint />
        </>
      )

    // Отдельно от expired: сеть недоступна — не повод стирать токен
    // и заставлять переподключаться.
    case 'unreachable':
      return (
        <>
          <p>Conwix недоступен. Подключение сохранено.</p>
          <p>
            <strong>{state.connection.companyName}</strong>
          </p>
        </>
      )

    case 'disconnected':
      return (
        <>
          <p>Расширение не подключено.</p>
          <ConnectHint />
        </>
      )
  }
}

function ConnectHint(): React.JSX.Element {
  return (
    <p>
      Откройте Conwix и нажмите «Подключить расширение»: <br />
      {API_BASE_URL}
    </p>
  )
}

async function check(): Promise<State> {
  const connection = await readConnection(browserStorage())
  if (null === connection) {
    return { kind: 'disconnected' }
  }

  try {
    const me = await fetchMe(connection.token)

    // Название могло измениться на стороне сервиса — источником правды
    // остаётся ответ, не то, что лежит в storage с момента подключения.
    return {
      kind: 'connected',
      connection: {
        ...connection,
        companyId: me.company.id,
        companyName: me.company.name,
      },
    }
  } catch (error) {
    if (isUnauthorized(error)) {
      await clearConnection(browserStorage())

      return { kind: 'expired' }
    }

    return { kind: 'unreachable', connection }
  }
}

async function disconnect(): Promise<State> {
  await clearConnection(browserStorage())

  return { kind: 'disconnected' }
}
