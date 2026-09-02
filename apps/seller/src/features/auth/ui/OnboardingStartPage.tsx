import { CircleCheck } from 'lucide-react'

import { Card, StatusPanel } from '../../../../../../packages/ui/src'

export function OnboardingStartPage() {
  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <Card>
          <StatusPanel
            icon={<CircleCheck aria-hidden="true" size={20} />}
            title="Следующий этап"
            description="На следующем этапе Stage 4 понадобятся: Название магазина, Ozon Client-Id и Ozon Api-Key."
            tone="accent"
          />
        </Card>
      </div>
    </div>
  )
}
