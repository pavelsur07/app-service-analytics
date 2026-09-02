import { useMutation } from '@tanstack/react-query'

import { apiPost } from '../../../api/client'
import type { components, paths } from '../../../api/schema'

type SelfRegistrationResponse =
  components['schemas']['SelfRegistrationResponse']
type ResendOperation = NonNullable<
  paths['/api/auth/email-verification/resend']['post']
>
export type ResendConfirmationRequest = NonNullable<
  ResendOperation['requestBody']
>['content']['application/json']

export function resendRequest(email: string): ResendConfirmationRequest {
  return { email }
}

export function useResendConfirmation() {
  return useMutation<SelfRegistrationResponse, Error, string>({
    mutationFn: (email) =>
      apiPost<SelfRegistrationResponse>(
        '/api/auth/email-verification/resend',
        resendRequest(email),
      ),
  })
}
