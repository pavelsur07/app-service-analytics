// Единственное место в приложении, знающее про API расширений браузера.
//
// Тип объявлен здесь, а не пакетом @types/chrome: странице доступны ровно
// два вызова, и тянуть полное описание всех API расширений ради них
// незачем (новая зависимость требует согласования — CLAUDE.md).
interface ExtensionBridge {
  readonly runtime?: {
    sendMessage(
      extensionId: string,
      message: unknown,
      callback: (response: unknown) => void,
    ): void
    readonly lastError?: { message?: string }
  }
}

export interface ConnectResult {
  readonly ok: boolean
  readonly companyName?: string
}

/**
 * Идентификатор установленного расширения. Его публикует само расширение
 * скриптом на страницах приложения (apps/extension/src/content/announce.ts):
 * без ключа в манифесте id у каждой установки свой, и держать его
 * синхронно в двух приложениях было бы источником рассинхронизации.
 *
 * undefined означает «расширение не установлено» — предлагать подключение
 * в этом случае нечему.
 */
export function installedExtensionId(): string | undefined {
  return document.documentElement.dataset.conwixExtensionId
}

/**
 * Передача выпущенного токена расширению (ADR-010). Канал —
 * externally_connectable, браузер сам проверяет, что страница с нашего
 * домена: токен не уходит ни в URL, ни в localStorage, ни через
 * postMessage, который прочитал бы любой скрипт на странице.
 */
export async function sendTokenToExtension(
  extensionId: string,
  token: string,
): Promise<ConnectResult> {
  const bridge = (window as unknown as { chrome?: ExtensionBridge }).chrome

  if (bridge?.runtime === undefined) {
    return { ok: false }
  }

  const runtime = bridge.runtime

  return new Promise<ConnectResult>((resolve) => {
    runtime.sendMessage(
      extensionId,
      { type: 'conwix:connect', token },
      (response) => {
        // lastError выставляется, когда расширение не ответило (удалено,
        // выключено, обновляется). Не прочитать его — предупреждение
        // в консоли; не обработать — «вечная загрузка» на экране.
        if (runtime.lastError !== undefined) {
          resolve({ ok: false })

          return
        }

        resolve(parseConnectResult(response))
      },
    )
  })
}

function parseConnectResult(value: unknown): ConnectResult {
  if (value === null || typeof value !== 'object') {
    return { ok: false }
  }

  const candidate = value as Record<string, unknown>
  if (candidate.ok !== true) {
    return { ok: false }
  }

  return typeof candidate.companyName === 'string'
    ? { ok: true, companyName: candidate.companyName }
    : { ok: true }
}
