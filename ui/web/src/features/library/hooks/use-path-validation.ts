import { useState, useCallback, useRef, useEffect } from 'react'

export function usePathValidation() {
  const [result, setResult] = useState<{
    valid: boolean
    error: string | null
    resolvedPath: string | null
  } | null>(null)
  const [isValidating, setIsValidating] = useState(false)

  const owner = useRef(0)
  useEffect(
    () => () => {
      owner.current++
    },
    [],
  )

  const validate = useCallback(async (path: string) => {
    const attempt = ++owner.current

    if (!path || path.trim() === '') {
      setIsValidating(false)
      setResult(null)
      return
    }

    setIsValidating(true)
    try {
      const { validatePath } = await import('../api/library-api')
      if (attempt !== owner.current) return

      const res = await validatePath(path)
      if (attempt !== owner.current) return

      setResult({
        valid: res.valid,
        error: res.error,
        resolvedPath: res.resolvedPath,
      })
    } catch {
      if (attempt !== owner.current) return

      setResult({
        valid: false,
        error: 'Validation request failed',
        resolvedPath: null,
      })
    } finally {
      if (attempt === owner.current) {
        setIsValidating(false)
      }
    }
  }, [])

  const reset = useCallback(() => {
    owner.current++
    setResult(null)
    setIsValidating(false)
  }, [])

  return { result, isValidating, validate, reset }
}
