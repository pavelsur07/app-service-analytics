import { useMutation } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import {
  sendTokenToExtension,
  type ConnectResult,
} from '../lib/browserExtension'

type IssueExtensionTokenResponse =
  components['schemas']['IssueExtensionTokenResponse']

/**
 * Подключение расширения в два шага (ADR-010): приложение выпускает токен
 * под сессией, затем передаёт его расширению. Секрет приходит в ответе
 * один раз и нигде не сохраняется на стороне приложения — ни в состоянии,
 * ни в кэше запросов: единственный его получатель — расширение.
 *
 * Инвалидации кэша нет намеренно: выпуск токена ничего из показанного
 * на экранах не меняет.
 */
export function useConnectExtension(companyId: string) {
  return useMutation<ConnectResult, Error, string>({
    mutationFn: async (extensionId: string) => {
      const issued =
        await createCompanyApiClient(
          companyId,
        ).post<IssueExtensionTokenResponse>('/extension-tokens')

      return sendTokenToExtension(extensionId, issued.token)
    },
  })
}
