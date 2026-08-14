import { useState } from 'react'
import { CircleCheck, CircleX } from 'lucide-react'
import { ApiError } from '../../../api/ApiError'
import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { replaceCredentialsFailure } from '../lib/replaceCredentialsError'
import { useReplaceCredentials } from '../model/useReplaceCredentials'

interface Props {
  companyId: string
  marketplaceAccountId: string
  externalShopId: string
  version: number
}

/**
 * Форма замены ключей подключения (ADR-007).
 *
 * До неё письмо о сломанном подключении и метка на экране заканчивались
 * словами «напишите нам»: клиент видел проблему, знал решение и не мог
 * его применить.
 */
export function ReplaceCredentialsForm({
  companyId,
  marketplaceAccountId,
  externalShopId,
  version,
}: Props) {
  const [open, setOpen] = useState(false)
  const [apiKey, setApiKey] = useState('')
  const mutation = useReplaceCredentials(companyId)

  if (!open) {
    return (
      <div>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          onClick={() => {
            setOpen(true)
          }}
        >
          Заменить ключ
        </Button>
      </div>
    )
  }

  const failure =
    mutation.error instanceof ApiError
      ? replaceCredentialsFailure(mutation.error.code)
      : mutation.error instanceof Error
        ? replaceCredentialsFailure(null)
        : null

  return (
    <form
      className="flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate(
          {
            marketplaceAccountId,
            // Client-Id не спрашиваем: он и есть идентификатор кабинета,
            // под которым подключение заведено. Дать его редактировать
            // значит предложить человеку способ привязать сюда чужой
            // магазин — сервер такой ключ отвергнет, но объяснять это
            // ошибкой хуже, чем не создавать возможность.
            clientId: externalShopId,
            apiKey,
            version,
          },
          {
            onSuccess: () => {
              // Секрет не остаётся ни в поле, ни в состоянии компонента
              // дольше самой отправки.
              setApiKey('')
              setOpen(false)
            },
          },
        )
      }}
    >
      <Input
        label={`Новый Api-Key кабинета ${externalShopId}`}
        hint="Кабинет Ozon → Настройки → API-ключи"
        // password, а не text: ключ не должен оставаться на экране
        // у человека за спиной и не попадает в автозаполнение.
        type="password"
        autoComplete="off"
        spellCheck={false}
        value={apiKey}
        onChange={(event) => {
          setApiKey(event.target.value)
        }}
        required
      />

      {failure !== null && (
        <Card tone="negative">
          <StatusPanel
            description={failure.description}
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title={failure.title}
            tone="negative"
          />
        </Card>
      )}

      {mutation.isSuccess && (
        <Card>
          {/* Тона positive у StatusPanel нет — успех показывается
              акцентным, как и всё, что не отказ. */}
          <StatusPanel
            description="Площадка приняла ключ, синхронизация продолжится в ближайшие полчаса."
            icon={<CircleCheck aria-hidden="true" size={20} />}
            role="status"
            title="Ключ заменён"
            tone="accent"
          />
        </Card>
      )}

      <div className="flex items-center gap-2">
        <Button type="submit" size="compact" disabled={mutation.isPending}>
          {mutation.isPending ? 'Проверяем у площадки…' : 'Сохранить'}
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          disabled={mutation.isPending}
          onClick={() => {
            setApiKey('')
            setOpen(false)
          }}
        >
          Отмена
        </Button>
      </div>
    </form>
  )
}
