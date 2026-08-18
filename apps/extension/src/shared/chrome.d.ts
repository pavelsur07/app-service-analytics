// Ручное объявление вместо пакета @types/chrome: используется десяток
// вызовов, а пакет тянет полное DefinitelyTyped-описание всех API
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
      /**
       * Вкладка отправителя. windowId доступен без разрешения `tabs` —
       * оно нужно только для url и title чужой вкладки.
       */
      tab?: { id?: number; windowId?: number }
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

  namespace windows {
    /** Фоновое окно под снятие цены (ADR-014). Разрешений не требует. */
    function create(info: {
      url: string
      focused?: boolean
      state?: 'minimized' | 'normal'
    }): Promise<{ id?: number }>

    function remove(windowId: number): Promise<void>
  }

  namespace alarms {
    interface Alarm {
      name: string
    }

    function create(
      name: string,
      info: { periodInMinutes?: number; delayInMinutes?: number },
    ): void

    /** Есть ли уже такой будильник — чтобы не сбрасывать его отсчёт. */
    function get(name: string): Promise<Alarm | undefined>

    function getAll(): Promise<Alarm[]>

    function clear(name: string): Promise<boolean>

    const onAlarm: {
      addListener(callback: (alarm: Alarm) => void): void
    }
  }
}
