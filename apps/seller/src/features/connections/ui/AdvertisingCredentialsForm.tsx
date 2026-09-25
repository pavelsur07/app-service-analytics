import { useState } from 'react'
import { CircleX } from 'lucide-react'
import { ApiError } from '../../../api/ApiError'
import {
  Button,
  Card,
  Input,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { connectAdvertisingFailure } from '../lib/connectAdvertisingError'
import { useConnectAdvertising } from '../model/useConnectAdvertising'

interface Props {
  companyId: string
  marketplaceAccountId: string
  version: number
  // Реклама уже подключена — форма заменяет ключ, а не добавляет.
  connected: boolean
}

/**
 * Ввод рекламного ключа Performance API (ADR-026). У рекламы свои ключи —
 * client_id и client_secret сервисного аккаунта, не Api-Key кабинета.
 */
export function AdvertisingCredentialsForm({
  companyId,
  marketplaceAccountId,
  version,
  connected,
}: Props) {
  const [open, setOpen] = useState(false)
  const [clientId, setClientId] = useState('')
  const [clientSecret, setClientSecret] = useState('')
  const mutation = useConnectAdvertising(companyId)

  const close = () => {
    // Секрет не остаётся ни в поле, ни в состоянии компонента.
    setClientSecret('')
    setOpen(false)
  }

  const openForm = () => {
    // Исход прошлой отправки к новой не относится: без сброса форма
    // сразу показала бы «ключ сохранён» или старую ошибку.
    mutation.reset()
    setOpen(true)
  }

  if (!open) {
    return (
      <div>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          onClick={openForm}
        >
          {connected ? 'Заменить рекламный ключ' : 'Подключить рекламу'}
        </Button>
      </div>
    )
  }

  const failure =
    mutation.error instanceof ApiError
      ? connectAdvertisingFailure(mutation.error.code)
      : mutation.error instanceof Error
        ? connectAdvertisingFailure(null)
        : null

  return (
    <form
      className="flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate(
          { marketplaceAccountId, clientId, clientSecret, version },
          // Успех виден по самому списку: он перечитывается, и метка
          // рекламы меняется на «подключена».
          { onSuccess: close },
        )
      }}
    >
      <Input
        label="client_id рекламного ключа"
        hint="Кабинет Ozon → Настройки → API-ключи → Performance API"
        autoComplete="off"
        spellCheck={false}
        value={clientId}
        onChange={(event) => {
          setClientId(event.target.value)
        }}
        required
      />
      <Input
        label="client_secret"
        // password: секрет не должен оставаться на экране и в автозаполнении.
        type="password"
        autoComplete="off"
        spellCheck={false}
        value={clientSecret}
        onChange={(event) => {
          setClientSecret(event.target.value)
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

      <div className="flex items-center gap-2">
        <Button type="submit" size="compact" disabled={mutation.isPending}>
          {mutation.isPending ? 'Проверяем у площадки…' : 'Сохранить'}
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          disabled={mutation.isPending}
          onClick={close}
        >
          Отмена
        </Button>
      </div>
    </form>
  )
}
