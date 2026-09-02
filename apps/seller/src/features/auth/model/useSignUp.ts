import { useMutation } from '@tanstack/react-query'

import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import type {
  RegistrationPayload,
  SignUpRequest,
} from '../lib/registrationPayload'

type SelfRegistrationResponse =
  components['schemas']['SelfRegistrationResponse']

export type SignUpVariables = {
  request: RegistrationPayload
  captchaToken: SignUpRequest['captchaToken']
}

export function signUpRequest(
  request: RegistrationPayload,
  captchaToken: SignUpRequest['captchaToken'],
): SignUpRequest {
  return { ...request, captchaToken }
}

export function useSignUp() {
  return useMutation<SelfRegistrationResponse, Error, SignUpVariables>({
    mutationFn: ({ request, captchaToken }) =>
      apiPost<SelfRegistrationResponse>(
        '/api/auth/sign-up',
        signUpRequest(request, captchaToken),
      ),
  })
}
