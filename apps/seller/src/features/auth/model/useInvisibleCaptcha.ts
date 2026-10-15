import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useSmartCaptchaLoader } from '@yandex/smart-captcha'

import {
  acceptCaptcha,
  failCaptcha,
  failCaptchaLoader,
  resetCaptcha,
  retryCaptcha,
  showChallenge,
  startCaptcha,
} from '../lib/captchaFlow'
import type { CaptchaFlowState } from '../lib/captchaFlow'
import { scheduleCaptchaLoaderTimeout } from '../lib/captchaLoaderGuard'

const CAPTCHA_RETRY_MESSAGE =
  'Не удалось подтвердить проверку. Попробуйте ещё раз'
const CAPTCHA_UNAVAILABLE_MESSAGE =
  'Проверка временно недоступна. Попробуйте позже'

interface InvisibleCaptchaOptions<TRequest> {
  // Капча пройдена: страница отправляет запрос и по завершении
  // (успех или отказ) вызывает settle().
  submit(request: TRequest, captchaToken: string): void
}

// Невидимая SmartCaptcha для публичной формы (ADR-021, ADR-037):
// загрузка виджета со сторожем таймаута, автомат captchaFlow,
// перемонтирование виджета после сбоя и повтор. Переходы — чистые
// функции captchaFlow (покрыты тестами); здесь только связь с React.
export function useInvisibleCaptcha<TRequest>({
  submit,
}: InvisibleCaptchaOptions<TRequest>) {
  const [revision, setRevision] = useState(0)
  const [flow, setFlow] =
    useState<CaptchaFlowState<TRequest>>(resetCaptcha<TRequest>())
  const flowRef = useRef<CaptchaFlowState<TRequest>>(flow)

  const updateFlow = useCallback((next: CaptchaFlowState<TRequest>) => {
    flowRef.current = next
    setFlow(next)
  }, [])

  const failProviderLoad = useCallback(() => {
    const current = flowRef.current
    const next = failCaptchaLoader(current, CAPTCHA_UNAVAILABLE_MESSAGE)

    if (next !== current) {
      updateFlow(next)
    }
  }, [updateFlow])
  const smartCaptcha = useSmartCaptchaLoader(undefined, failProviderLoad)

  useEffect(() => {
    if (flow.status !== 'checking' || smartCaptcha !== undefined) {
      return
    }

    return scheduleCaptchaLoaderTimeout(failProviderLoad)
  }, [failProviderLoad, flow.status, smartCaptcha])

  const failAndRemount = (message: string) => {
    const current = flowRef.current
    const next = failCaptcha(current, message)

    if (next === current) {
      return
    }

    setRevision((value) => value + 1)
    updateFlow(next)
  }

  // Перед разбором формы: после сбоя загрузки виджета повтор возможен
  // только перезагрузкой страницы. true — отправку формы продолжать.
  const beforeSubmit = (event: FormEvent<HTMLFormElement>): boolean => {
    if (retryCaptcha(flowRef.current).action === 'reload-page') {
      event.preventDefault()
      window.location.reload()
      return false
    }

    return true
  }

  // Форма прошла проверку: показать виджет и ждать токен.
  const begin = (request: TRequest) => {
    const retry = retryCaptcha(flowRef.current)

    if (retry.action === 'reload-page') {
      window.location.reload()
      return
    }

    updateFlow(startCaptcha(retry.state, request))
  }

  // Запрос завершён: новый виджет для следующей попытки.
  const settle = () => {
    setRevision((value) => value + 1)
    updateFlow(resetCaptcha<TRequest>())
  }

  const widget =
    smartCaptcha === undefined
      ? null
      : {
          key: revision,
          props: {
            sitekey: import.meta.env.VITE_SMARTCAPTCHA_CLIENT_KEY,
            visible: flow.status === 'checking',
            onChallengeVisible: () => {
              updateFlow(showChallenge(flowRef.current))
            },
            onChallengeHidden: () => {
              failAndRemount(CAPTCHA_RETRY_MESSAGE)
            },
            onNetworkError: () => {
              failAndRemount(CAPTCHA_UNAVAILABLE_MESSAGE)
            },
            onTokenExpired: () => {
              failAndRemount(CAPTCHA_RETRY_MESSAGE)
            },
            onJavascriptError: () => {
              if (flowRef.current.status !== 'checking') {
                return
              }

              setRevision((value) => value + 1)
              updateFlow(
                failCaptchaLoader(flowRef.current, CAPTCHA_UNAVAILABLE_MESSAGE),
              )
            },
            onSuccess: (captchaToken: string) => {
              const current = flowRef.current
              const next = acceptCaptcha(current, captchaToken)

              if (next === current || next.status !== 'submitting') {
                return
              }

              updateFlow(next)
              submit(next.request, captchaToken)
            },
          },
        }

  return {
    busy: flow.status === 'checking' || flow.status === 'submitting',
    failed: flow.status === 'failed',
    failureMessage: flow.status === 'failed' ? flow.message : null,
    beforeSubmit,
    begin,
    settle,
    widget,
  }
}
