import { useState } from 'react'
import { CircleX } from 'lucide-react'
import { ApiError } from '../../../api/ApiError'
import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { discardConnectionFailure } from '../lib/discardConnectionError'
import { useDiscardConnection } from '../model/useDiscardConnection'

interface Props {
  companyId: string
  marketplaceAccountId: string
  externalShopId: string
}

/**
 * Удаление подключения, которое ничего не загрузило.
 *
 * Клиент может подключить не тот кабинет: номер настоящий, но другого
 * магазина, — а исправить это нечем (external_shop_id неизменяем,
 * замена ключа на другой кабинет отвергается). Кнопка видна всегда,
 * не только у заведомо пустых подключений: сервер сам знает, есть ли
 * уже загруженные данные, и отклонит удаление своим кодом — гадать
 * об этом на экране незачем и опасно (можно ошибиться в другую сторону).
 *
 * Подтверждение — действие необратимо (строка удаляется, а не
 * помечается): тот же двухшаговый приём, что у ReplaceCredentialsForm
 * (свёрнутая кнопка → открытая форма), только вместо полей — предупреждение.
 */
export function DiscardConnectionButton({
  companyId,
  marketplaceAccountId,
  externalShopId,
}: Props) {
  const [confirming, setConfirming] = useState(false)
  const mutation = useDiscardConnection(companyId)

  if (!confirming) {
    return (
      <Button
        type="button"
        variant="secondary"
        size="compact"
        onClick={() => {
          setConfirming(true)
        }}
      >
        Удалить подключение
      </Button>
    )
  }

  const failure =
    mutation.error instanceof ApiError
      ? discardConnectionFailure(mutation.error.code)
      : mutation.error instanceof Error
        ? discardConnectionFailure(null)
        : null

  return (
    <div className="flex flex-col gap-3">
      <Card tone="negative">
        <StatusPanel
          description={`Магазин ${externalShopId} и его настройки будут удалены навсегда. Отменить это действие нельзя. Удалить можно только подключение, которое ничего не загрузило, — если данные уже есть, сервер откажет.`}
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Удалить подключение без возврата?"
          tone="negative"
        />
      </Card>

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
        <Button
          type="button"
          size="compact"
          disabled={mutation.isPending}
          onClick={() => {
            mutation.mutate(marketplaceAccountId, {
              onSuccess: () => {
                setConfirming(false)
              },
            })
          }}
        >
          {mutation.isPending ? 'Удаляем…' : 'Удалить навсегда'}
        </Button>
        <Button
          type="button"
          variant="secondary"
          size="compact"
          disabled={mutation.isPending}
          onClick={() => {
            setConfirming(false)
          }}
        >
          Отмена
        </Button>
      </div>
    </div>
  )
}
