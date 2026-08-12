import { CircleCheck, CircleX, LoaderCircle, Puzzle } from 'lucide-react'
import { useParams } from 'react-router'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import { extensionRecipient } from '../lib/browserExtension'
import { useConnectExtension } from '../model/useConnectExtension'

// Экран подключения расширения браузера (ADR-010). Токен выпускается
// здесь, под сессией, и уходит расширению напрямую — на экране он
// не показывается и в буфер обмена не копируется: незачем, получатель
// у него ровно один.
export function ExtensionConnectPage() {
  const { companyId } = useParams<{ companyId: string }>()
  const recipient = extensionRecipient()
  const connect = useConnectExtension(companyId ?? '')

  if (companyId === undefined) {
    return null
  }

  return (
    <div className="p-6">
      <Card>
        {recipient.kind === 'not-installed' ? (
          <StatusPanel
            icon={<Puzzle aria-hidden="true" size={20} />}
            title="Расширение не установлено"
            description="Установите расширение Conwix для браузера и обновите страницу."
          />
        ) : recipient.kind === 'not-configured' ? (
          // Идентификатор расширения ещё не закреплён. Отправлять токен
          // тому, кого назвал DOM, нельзя: подменить атрибут может любое
          // другое расширение на этой странице.
          <StatusPanel
            icon={<CircleX aria-hidden="true" size={20} />}
            title="Подключение недоступно"
            description="Расширение ещё не опубликовано. Напишите в поддержку."
          />
        ) : (
          <Connect
            pending={connect.status === 'pending'}
            result={connect.data}
            failed={connect.status === 'error'}
            onConnect={() => connect.mutate(recipient.id)}
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

  // Два разных отказа, и человеку от них нужны разные действия.
  // Сваливать их в одно сообщение — значит отнимать у него (и у нас
  // при разборе) единственную подсказку, где именно оборвалось.
  const failure =
    failed === true
      ? {
          title: 'Не удалось выпустить токен',
          description: 'Проверьте соединение с Conwix и попробуйте ещё раз.',
        }
      : result?.ok === false
        ? {
            title: 'Расширение не приняло подключение',
            description:
              'Оно не смогло связаться с Conwix. Проверьте, что расширение включено, и попробуйте ещё раз.',
          }
        : null

  return (
    <>
      {failure !== null ? (
        <StatusPanel
          icon={<CircleX aria-hidden="true" size={20} />}
          title={failure.title}
          description={failure.description}
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
