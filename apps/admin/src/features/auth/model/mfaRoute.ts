import type { components } from '../../../api/schema'

export function mfaRoute(
  state: components['schemas']['AdminMfaStatusResponse']['state'],
): '/mfa' | '/accounts' {
  return state === 'authenticated' ? '/accounts' : '/mfa'
}
