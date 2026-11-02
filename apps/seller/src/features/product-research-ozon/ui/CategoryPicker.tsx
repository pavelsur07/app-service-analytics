import { useEffect, useId, useState } from 'react'

import { Button, Input } from '../../../../../../packages/ui/src'
import { categoryCanSearch, errorText } from '../lib/research'
import { useCategories } from '../model/queries'

export function CategoryPicker({
  companyId,
  selected,
  onSelect,
}: {
  companyId: string
  selected: string | null
  onSelect: (path: string | null) => void
}) {
  const [text, setText] = useState(selected ?? '')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)
  const listId = useId()
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(open ? text.trim() : '')
    }, 250)
    return () => {
      clearTimeout(timer)
    }
  }, [text, open])
  const query = useCategories(companyId, debounced)
  const items =
    debounced === text.trim() && query.isSuccess ? query.data.items : []
  const choose = (path: string) => {
    setText(path)
    setOpen(false)
    onSelect(path)
  }
  return (
    <div className="flex flex-col gap-2">
      <Input
        label="Категория Ozon"
        value={text}
        maxLength={200}
        autoComplete="off"
        role="combobox"
        aria-autocomplete="list"
        aria-expanded={open && categoryCanSearch(text)}
        aria-controls={listId}
        aria-activedescendant={
          open && items[active] !== undefined
            ? `${listId}-${active}`
            : undefined
        }
        hint={
          selected === null
            ? 'Введите не менее 3 букв и выберите полный путь из подсказок.'
            : 'Категория выбрана из справочника MPStats.'
        }
        onChange={(event) => {
          setText(event.target.value)
          onSelect(null)
          setOpen(true)
          setActive(0)
        }}
        onKeyDown={(event) => {
          if (event.key === 'Escape') setOpen(false)
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()
            setOpen(true)
            setActive((value) =>
              Math.max(
                0,
                Math.min(
                  items.length - 1,
                  value + (event.key === 'ArrowDown' ? 1 : -1),
                ),
              ),
            )
          }
          if (event.key === 'Enter' && open) {
            event.preventDefault()
            const item = items[active]
            if (item !== undefined) choose(item.path)
          }
        }}
      />
      {open && categoryCanSearch(text) && (
        <div className="rounded-md border border-border-default bg-surface-raised p-2">
          {(query.isFetching || text.trim() !== debounced) && (
            <p role="status" className="text-sm text-text-secondary">
              Ищем категории…
            </p>
          )}
          {query.isError && (
            <div
              role="alert"
              className="flex flex-col items-start gap-2 text-sm text-negative-text"
            >
              <p>{errorText(query.error)}</p>
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void query.refetch()
                }}
              >
                Повторить поиск
              </Button>
            </div>
          )}
          {query.isSuccess &&
            !query.isFetching &&
            items.length === 0 &&
            text.trim() === debounced && (
              <p className="text-sm text-text-secondary">
                Категории не найдены. Уточните название.
              </p>
            )}
          <ul id={listId} role="listbox" aria-label="Подсказки категорий">
            {items.map((item, index) => (
              <li key={item.path} role="presentation">
                <button
                  id={`${listId}-${index}`}
                  type="button"
                  role="option"
                  aria-selected={active === index}
                  className={`w-full rounded px-2 py-2 text-left text-sm break-words hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-accent-default ${active === index ? 'bg-accent-subtle' : ''}`}
                  onClick={() => {
                    choose(item.path)
                  }}
                >
                  {item.path}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
