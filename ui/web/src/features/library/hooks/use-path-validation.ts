import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { useState, useCallback, useRef, useEffect } from 'react'

export function usePathValidation() {
  const [result, setResult] = useState<{
    valid: boolean
    error: string | null
    resolvedPath: string | null
  } | null>(null)
  const [isValidating, setIsValidating] = useState(false)

  const owner = useRef(0)
  const invalidateAttempt = useCallback(() => {
    owner.current++
  }, [])
  const reset = useCallback(() => {
    invalidateAttempt()
    setResult(null)
    setIsValidating(false)
  }, [invalidateAttempt])

  useEffect(() => {
    const unsubscribe = subscribeOwnership(reset)
    return () => {
      invalidateAttempt()
      unsubscribe()
    }
  }, [reset, invalidateAttempt])

  const validate = useCallback(async (path: string) => {
    const attempt = ++owner.current
    const ownership = captureOwnership()

    if (!path || path.trim() === '') {
      setIsValidating(false)
      setResult(null)
      return
    }

    setIsValidating(true)
    try {
      const { validatePath } = await import('../api/library-api')
      if (attempt !== owner.current || !isCurrentOwnership(ownership)) return

      const res = await validatePath(path)
      if (attempt !== owner.current || !isCurrentOwnership(ownership)) return

      setResult({
        valid: res.valid,
        error: res.error,
        resolvedPath: res.resolvedPath,
      })
    } catch {
      if (attempt !== owner.current || !isCurrentOwnership(ownership)) return

      setResult({
        valid: false,
        error: 'Validation request failed',
        resolvedPath: null,
      })
    } finally {
      if (attempt === owner.current && isCurrentOwnership(ownership)) {
        setIsValidating(false)
      }
    }
  }, [])

  return { result, isValidating, validate, reset }
}
