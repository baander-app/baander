import { useState, useCallback } from 'react'

export function usePathValidation() {
  const [result, setResult] = useState<{
    valid: boolean
    error: string | null
    resolvedPath: string | null
  } | null>(null)
  const [isValidating, setIsValidating] = useState(false)

  const validate = useCallback(async (path: string) => {
    if (!path || path.trim() === '') {
      setResult(null)
      return
    }

    setIsValidating(true)
    try {
      const { validatePath } = await import('../api/library-api')
      const res = await validatePath(path)
      setResult({
        valid: res.valid,
        error: res.error,
        resolvedPath: res.resolvedPath,
      })
    } catch {
      setResult({ valid: false, error: 'Validation request failed', resolvedPath: null })
    } finally {
      setIsValidating(false)
    }
  }, [])

  const reset = useCallback(() => setResult(null), [])

  return { result, isValidating, validate, reset }
}
