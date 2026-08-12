import { fetchMe } from '../api/client'
import {
  browserStorage,
  writeConnection,
  type Connection,
} from '../shared/connection'

// Service worker MV3 засыпает примерно через полминуты простоя, поэтому
// состояния в памяти здесь нет и быть не может: единственное, что он
// делает, — принимает токен от приложения и кладёт его в storage.

interface ConnectMessage {
  readonly type: 'conwix:connect'
  readonly token: string
}

type ConnectResult =
  | { readonly ok: true; readonly companyName: string }
  | { readonly ok: false; readonly error: string }

/**
 * Токен приходит из SPA через externally_connectable. Круг отправителей
 * ограничен манифестом (только домен приложения) — браузер не доставит
 * сюда сообщение с чужой страницы, поэтому проверять origin повторно
 * незачем, а вот форму сообщения проверить надо.
 *
 * companyId и название не берутся из сообщения: их сообщает сервер
 * в ответ на предъявление токена. Иначе страница могла бы записать
 * расширению чужую компанию рядом с настоящим токеном, и расширение
 * показывало бы данные под неверной подписью.
 */
chrome.runtime.onMessageExternal.addListener(
  (message, _sender, sendResponse) => {
    if (!isConnectMessage(message)) {
      sendResponse({
        ok: false,
        error: 'unsupported_message',
      } satisfies ConnectResult)

      return false
    }

    void connect(message.token).then(sendResponse)

    // true — ответ будет асинхронным; без него канал закроется раньше,
    // чем сеть ответит, и приложение не узнает результат.
    return true
  },
)

async function connect(token: string): Promise<ConnectResult> {
  try {
    // Токен проверяется до записи: подключённым расширение считается
    // только когда сервер подтвердил, кто это и какая компания.
    const me = await fetchMe(token)
    const connection: Connection = {
      token,
      companyId: me.company.id,
      companyName: me.company.name,
    }
    await writeConnection(browserStorage(), connection)

    return { ok: true, companyName: connection.companyName }
  } catch {
    return { ok: false, error: 'connect_failed' }
  }
}

function isConnectMessage(value: unknown): value is ConnectMessage {
  if (null === value || 'object' !== typeof value) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    'conwix:connect' === candidate.type &&
    'string' === typeof candidate.token &&
    '' !== candidate.token
  )
}
