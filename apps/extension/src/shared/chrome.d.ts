// Ручное объявление вместо пакета @types/chrome: используются четыре
// вызова, а пакет тянет полное DefinitelyTyped-описание всех API
// расширений. Новая внешняя зависимость требует согласования
// (CLAUDE.md, «Когда остановиться и спросить»), и ради двадцати строк
// её заводить незачем. Появится десяток разных API — вернуться
// к этому решению.

declare namespace chrome {
  namespace storage {
    interface StorageArea {
      get(keys: string[] | string | null): Promise<Record<string, unknown>>
      set(items: Record<string, unknown>): Promise<void>
      remove(keys: string[] | string): Promise<void>
      clear(): Promise<void>
    }

    const local: StorageArea
  }

  namespace runtime {
    const id: string

    interface MessageSender {
      id?: string
      origin?: string
      url?: string
    }

    interface ExternalMessageEvent {
      addListener(
        callback: (
          message: unknown,
          sender: MessageSender,
          sendResponse: (response: unknown) => void,
        ) => boolean | undefined,
      ): void
    }

    const onMessageExternal: ExternalMessageEvent

    interface MessageEvent {
      addListener(
        callback: (
          message: unknown,
          sender: MessageSender,
          sendResponse: (response: unknown) => void,
        ) => boolean | undefined,
      ): void
    }

    /** Сообщения между своими же частями расширения. */
    const onMessage: MessageEvent

    function sendMessage(message: unknown): Promise<unknown>
  }

  namespace alarms {
    interface Alarm {
      name: string
    }

    function create(
      name: string,
      info: { periodInMinutes?: number; delayInMinutes?: number },
    ): void

    const onAlarm: {
      addListener(callback: (alarm: Alarm) => void): void
    }
  }
}
