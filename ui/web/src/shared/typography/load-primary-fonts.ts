/**
 * Text covering the Latin, Latin Extended and Vietnamese slices of Geist, so all
 * three load before the first render.
 */
const PRIMARY_SAMPLE = 'Aa Ąą Ơơ'

/** The Geist faces every screen uses before any script-specific font. */
const PRIMARY_FACES = ['400 1em "Geist Variable"', '400 1em "Geist Mono Variable"'] as const

/** A slow or failed font request must never hold back the app; text then swaps in later. */
const PRIMARY_FONT_TIMEOUT_MS = 3000

/**
 * Loads the Geist faces before the first render. While a Geist slice is still
 * loading, Firefox starts fetching every later family in the stack whose
 * unicode-range covers the same characters, so a Latin-only screen would download
 * Noto CJK, Thai and Myanmar slices it never uses. Loading Geist first closes that
 * window. Resolves once the faces are ready, have failed, or the timeout passes.
 */
export async function loadPrimaryFonts(fonts: FontFaceSet | undefined): Promise<void> {
  if (!fonts) return

  const loads = Promise.all(PRIMARY_FACES.map((face) => fonts.load(face, PRIMARY_SAMPLE))).then(
    () => undefined,
    () => undefined,
  )
  let timer: ReturnType<typeof setTimeout> | undefined
  const timeout = new Promise<void>((resolve) => {
    timer = setTimeout(resolve, PRIMARY_FONT_TIMEOUT_MS)
  })

  await Promise.race([loads, timeout])
  clearTimeout(timer)
}
