import { useEffect, useRef, useState } from 'react'
import { Check, Copy, Pencil } from 'lucide-react'

import { Badge, Button } from '../../../../../../packages/ui/src'
import type { ShortLink } from '../model/useLinks'
import { useSetLinkStatus } from '../model/useSetLinkStatus'

export function LinksTable({
  links,
  selectedLinkId,
  onEdit,
  onSelect,
}: {
  links: readonly ShortLink[]
  selectedLinkId: string | null
  onEdit: (linkId: string) => void
  onSelect: (linkId: string) => void
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs text-text-muted">
            <th className="py-2">Название</th>
            <th className="py-2">Короткая ссылка</th>
            <th className="py-2">Состояние</th>
            <th className="py-2" />
          </tr>
        </thead>
        <tbody>
          {links.map((link) => (
            <LinkRow
              key={link.id}
              link={link}
              onEdit={onEdit}
              onSelect={onSelect}
              selected={selectedLinkId === link.id}
            />
          ))}
        </tbody>
      </table>
    </div>
  )
}

function LinkRow({
  link,
  selected,
  onEdit,
  onSelect,
}: {
  link: ShortLink
  selected: boolean
  onEdit: (linkId: string) => void
  onSelect: (linkId: string) => void
}) {
  const setStatus = useSetLinkStatus(link.id)
  const previousVersion = useRef(link.version)
  const [copyState, setCopyState] = useState<'idle' | 'copied' | 'error'>(
    'idle',
  )
  const active = link.status === 'active'

  useEffect(() => {
    if (previousVersion.current === link.version) {
      return
    }

    previousVersion.current = link.version
    setStatus.reset()
  }, [link.version, setStatus])

  const copy = async (): Promise<void> => {
    try {
      await navigator.clipboard.writeText(link.shortUrl)
      setCopyState('copied')
    } catch {
      setCopyState('error')
    }
  }

  return (
    <tr className="border-t border-border-default">
      <td className="py-3 pr-4">
        <button
          aria-pressed={selected}
          className="cursor-pointer text-left font-medium text-accent-default hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
          onClick={() => {
            onSelect(link.id)
          }}
          type="button"
        >
          <span className="sr-only">Показать переходы: </span>
          {link.name}
        </button>
        <div className="mt-1 max-w-80 truncate text-xs text-text-muted">
          {link.targetUrl}
        </div>
      </td>
      <td className="py-3 pr-4">
        <div className="flex items-center gap-2">
          <code>{link.code}</code>
          <Button
            aria-label={`Копировать ссылку ${link.name}`}
            onClick={() => {
              void copy()
            }}
            size="compact"
            type="button"
            variant="ghost"
          >
            {copyState === 'copied' ? (
              <Check aria-hidden="true" size={14} />
            ) : (
              <Copy aria-hidden="true" size={14} />
            )}
          </Button>
        </div>
        {copyState === 'copied' && (
          <span className="text-xs text-positive-text" role="status">
            Скопировано
          </span>
        )}
        {copyState === 'error' && (
          <span className="text-xs text-negative-text" role="alert">
            Не удалось скопировать
          </span>
        )}
      </td>
      <td className="py-3 pr-4">
        <Badge tone={active ? 'positive' : 'negative'}>
          {active ? 'работает' : 'отключена'}
        </Badge>
        {setStatus.isError && (
          <div className="mt-1 text-xs text-negative-text" role="alert">
            {setStatus.error instanceof Error
              ? setStatus.error.message
              : 'Не удалось изменить состояние'}
          </div>
        )}
      </td>
      <td className="py-3 text-right">
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            aria-label="Редактировать"
            onClick={() => {
              onEdit(link.id)
            }}
            size="compact"
            type="button"
            variant="secondary"
          >
            <Pencil aria-hidden="true" size={14} />
            Изменить
          </Button>
          <Button
            loading={setStatus.isPending}
            onClick={() => {
              setStatus.mutate({
                status: active ? 'disabled' : 'active',
                version: link.version,
              })
            }}
            size="compact"
            type="button"
            variant="secondary"
          >
            {active ? 'Отключить' : 'Включить'}
          </Button>
        </div>
      </td>
    </tr>
  )
}
