import { CircleCheck, CircleX, LoaderCircle, Puzzle } from 'lucide-react'
import { useParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { installedExtensionId } from '../lib/browserExtension'
import { useConnectExtension } from '../model/useConnectExtension'

// Экран подключения расширения браузера (ADR-010). Токен выпускается
// здесь, под сессией, и уходит расширению напрямую — на экране он
// не показывается и в буфер обмена не копируется: незачем, получатель
// у него ровно один.
export function ExtensionConnectPage() {
  const { companyId } = useParams<{ companyId: string }>()
  const extensionId = installedExtensionId()
  const connect = useConnectExtension(companyId ?? '')

  if (companyId === undefined) {
    return null
  }

  return (
    <div className="p-6">
      <Card>
        {extensionId === undefined ? (
          <StatusPanel
            icon={<Puzzle aria-hidden="true" size={20} />}
            title="Расширение не установлено"
            description="Установите расширение Conwix для браузера и обновите страницу."
          />
        ) : (
          <Connect
            pending={connect.status === 'pending'}
            result={connect.data}
            failed={connect.status === 'error'}
            onConnect={() => connect.mutate(extensionId)}
          />
        )}
      </Card>
    </div>
  )
}

function Connect({
  pending,
  result,
  failed,
  onConnect,
}: {
  pending: boolean
  result: { ok: boolean; companyName?: string } | undefined
  failed: boolean
  onConnect: () => void
}) {
  if (pending) {
    return (
      <StatusPanel
        icon={
          <LoaderCircle aria-hidden="true" className="animate-spin" size={20} />
        }
        title="Подключаем расширение"
      />
    )
  }

  if (result?.ok === true) {
    return (
      <StatusPanel
        icon={<CircleCheck aria-hidden="true" size={20} />}
        title="Расширение подключено"
        // exactOptionalPropertyTypes: пропуск поля и явный undefined —
        // разные вещи; название компании расширение может и не прислать.
        {...(result.companyName === undefined
          ? {}
          : { description: result.companyName })}
      />
    )
  }

  // Отказ расширения и отказ сети выглядят для человека одинаково,
  // и действие у него одно — попробовать снова.
  const rejected = failed || result?.ok === false

  return (
    <>
      {rejected ? (
        <StatusPanel
          icon={<CircleX aria-hidden="true" size={20} />}
          title="Не удалось подключить"
          description="Проверьте, что расширение включено, и попробуйте ещё раз."
        />
      ) : (
        <StatusPanel
          icon={<Puzzle aria-hidden="true" size={20} />}
          title="Расширение установлено"
          description="Подключите его к этой компании, чтобы видеть аналитику на карточках товаров."
        />
      )}
      <Button onClick={onConnect}>Подключить расширение</Button>
    </>
  )
}
