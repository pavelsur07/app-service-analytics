import { MailCheck } from 'lucide-react'
import { Link } from 'react-router'

import { Card, StatusPanel } from '../../../../../../packages/ui/src'

const LINK_CLASS =
  'font-medium text-accent-default underline hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default'

interface Props {
  onResend?: (() => void) | undefined
}

export function EmailSentPage({ onResend }: Props = {}) {
  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <StatusPanel
            icon={<MailCheck aria-hidden="true" size={20} />}
            title="Проверьте почту"
            description="Если указанный адрес можно использовать, мы отправим на него письмо с инструкциями."
            tone="accent"
            action={
              <div className="flex flex-col items-center gap-2 text-sm">
                {onResend === undefined ? (
                  <Link className={LINK_CLASS} to="/resend-confirmation">
                    Отправить письмо ещё раз
                  </Link>
                ) : (
                  <button
                    className={LINK_CLASS}
                    type="button"
                    onClick={onResend}
                  >
                    Отправить письмо ещё раз
                  </button>
                )}
                <Link className={LINK_CLASS} to="/login">
                  Вернуться ко входу
                </Link>
              </div>
            }
          />
        </Card>
      </div>
    </div>
  )
}
