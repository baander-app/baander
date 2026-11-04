import { describe, expect, it } from 'vitest'
import { TYPOGRAPHY_ALLOWLIST, type CensusException, type CensusExceptionKind } from './allowlist'

type CensusRule =
  | 'font-size'
  | 'svg-font-size'
  | 'font-weight'
  | 'letter-spacing'
  | 'font-family'
  | 'canvas-font'
  | 'dense-size'
  | 'content-text-as'

interface CensusFinding {
  readonly file: string
  readonly line: number
  readonly rule: CensusRule
  readonly text: string
}

/** The one rule each kind of exception lifts. */
const EXCEPTION_RULE: Record<CensusExceptionKind, CensusRule> = {
  'admin-table': 'dense-size',
  badge: 'dense-size',
  chip: 'dense-size',
  canvas: 'canvas-font',
  'svg-viewbox': 'svg-font-size',
}

const LIGHT_WEIGHT = /(?<![\w.-])[123]00(?![\w.])|\blighter\b/
const ROLE_SIZE = /var\(--text-[a-z-]+-size\)/
const ROLE_TRACKING = /var\(--text-[a-z-]+-tracking\)/
const ROLE_FAMILY = /^['"]?var\(--font-(?:ui|mono)\)['"]?$/

interface DeclarationCheck {
  readonly pattern: RegExp
  readonly rule: CensusRule
  readonly violates: (value: string) => boolean
}

// CSS declarations end at `;`; style-object and JSX values end at `,` or `}`.
const CSS_VALUE = String.raw`\s*:\s*([^;\n]*)`
const OBJECT_VALUE = String.raw`\s*[:=]\s*([^,}\n]*)`

const DECLARATIONS: readonly DeclarationCheck[] = [
  { pattern: new RegExp(String.raw`(?<![\w-])font-size${CSS_VALUE}`, 'g'), rule: 'font-size', violates: (value) => !ROLE_SIZE.test(value) && !value.includes('--text-dense-size') },
  { pattern: new RegExp(String.raw`\bfontSize\s*:\s*([^,}\n]*)`, 'g'), rule: 'font-size', violates: () => true },
  { pattern: /\bfontSize\s*=\s*(\S*)/g, rule: 'svg-font-size', violates: () => true },
  { pattern: new RegExp(String.raw`(?<![\w-])font-weight${CSS_VALUE}`, 'g'), rule: 'font-weight', violates: (value) => LIGHT_WEIGHT.test(value) },
  { pattern: new RegExp(String.raw`\bfontWeight${OBJECT_VALUE}`, 'g'), rule: 'font-weight', violates: (value) => LIGHT_WEIGHT.test(value) },
  { pattern: new RegExp(String.raw`(?<![\w-])letter-spacing${CSS_VALUE}`, 'g'), rule: 'letter-spacing', violates: (value) => !ROLE_TRACKING.test(value) },
  { pattern: new RegExp(String.raw`\bletterSpacing${OBJECT_VALUE}`, 'g'), rule: 'letter-spacing', violates: (value) => !ROLE_TRACKING.test(value) },
  { pattern: new RegExp(String.raw`(?<![\w-])font-family${CSS_VALUE}`, 'g'), rule: 'font-family', violates: (value) => !ROLE_FAMILY.test(declaredValue(value)) },
  { pattern: new RegExp(String.raw`\bfontFamily${OBJECT_VALUE}`, 'g'), rule: 'font-family', violates: (value) => !ROLE_FAMILY.test(declaredValue(value)) },
  // The `font` shorthand sets the family too, so it never reads a role.
  { pattern: new RegExp(String.raw`(?<![\w.-])font${CSS_VALUE}`, 'g'), rule: 'font-family', violates: () => true },
  { pattern: /\.font\s*=\s*([^;\n]*)/g, rule: 'canvas-font', violates: () => true },
  { pattern: /--text-dense-size/g, rule: 'dense-size', violates: () => true },
  { pattern: /\btextRole\([^)]*\bdense\s*:\s*true\b/g, rule: 'dense-size', violates: () => true },
]

function declaredValue(value: string): string {
  return value.replace(/!important/, '').trim()
}

/** Blanks comments while keeping every offset and line number in place. */
function withoutComments(source: string): string {
  const blank = (text: string) => text.replace(/[^\n]/g, ' ')
  return source
    .replace(/\/\*[\s\S]*?\*\//g, blank)
    .replace(/(^|[^:\\'"`\w])(\/\/.*)$/gm, (_match, before: string, comment: string) => before + blank(comment))
}

function lineStarts(source: string): number[] {
  const starts = [0]
  for (let index = source.indexOf('\n'); index !== -1; index = source.indexOf('\n', index + 1)) starts.push(index + 1)
  return starts
}

function lineOf(starts: readonly number[], offset: number): number {
  let low = 0
  let high = starts.length - 1
  while (low < high) {
    const middle = Math.ceil((low + high) / 2)
    if (starts[middle] <= offset) low = middle
    else high = middle - 1
  }
  return low + 1
}

/** Names of components declared in this source as `styled(ContentText)`, directly or through another such wrapper. */
function contentTextWrappers(source: string): Set<string> {
  const wrappers = new Set<string>()
  const declarations = [...source.matchAll(/\b(?:const|let)\s+(\w+)\s*=\s*styled\(\s*(\w+)\s*\)/g)]
  let grew = true
  while (grew) {
    grew = false
    for (const [, name, base] of declarations) {
      if (!wrappers.has(name) && (base === 'ContentText' || wrappers.has(base))) {
        wrappers.add(name)
        grew = true
      }
    }
  }
  return wrappers
}

/** The attribute text of the JSX opening tag that starts at `offset`, with braced expressions removed. */
function openingTagAttributes(source: string, offset: number): string {
  let depth = 0
  let quote: string | null = null
  let attributes = ''
  for (let index = offset; index < source.length; index++) {
    const character = source[index]
    if (depth === 0 && quote !== null) {
      if (character === quote) quote = null
      attributes += character
      continue
    }
    if (character === '{') depth++
    else if (character === '}') depth--
    else if (depth === 0 && (character === '"' || character === "'")) quote = character
    else if (depth === 0 && character === '>') break
    if (depth === 0 && character !== '}') attributes += character
  }
  return attributes
}

function scanTypography(file: string, rawSource: string): CensusFinding[] {
  const source = withoutComments(rawSource)
  const starts = lineStarts(source)
  const lines = rawSource.split('\n')
  const findings: CensusFinding[] = []
  const report = (offset: number, rule: CensusRule) => {
    const line = lineOf(starts, offset)
    findings.push({ file, line, rule, text: lines[line - 1].trim() })
  }

  for (const { pattern, rule, violates } of DECLARATIONS) {
    for (const match of source.matchAll(pattern)) {
      if (violates(match[1] ?? '')) report(match.index, rule)
    }
  }

  // styled-components' `as` replaces the wrapped ContentText, dropping its `lang` and
  // `data-script`; ContentText's `element` prop chooses the tag instead.
  for (const wrapper of contentTextWrappers(source)) {
    for (const match of source.matchAll(new RegExp(String.raw`<${wrapper}(?=[\s/>])`, 'g'))) {
      if (/(?<![\w$])as\s*=/.test(openingTagAttributes(source, match.index + match[0].length))) report(match.index, 'content-text-as')
    }
  }

  return findings.sort((a, b) => a.line - b.line)
}

/** The token, mixin and global-style files implement the roles; tests are not shipped text. */
function isCensused(file: string): boolean {
  return !/^src\/shared\/(?:theme|typography)\//.test(file)
    && !/(?:^|\/)__tests__\//.test(file)
    && !/\.(?:test|spec)\.tsx?$/.test(file)
}

function unallowed(findings: readonly CensusFinding[], allowlist: readonly CensusException[]): string[] {
  return findings
    .filter((finding) => !allowlist.some((entry) => entry.file === finding.file && EXCEPTION_RULE[entry.kind] === finding.rule))
    .map((finding) => `${finding.file}:${finding.line} ${finding.rule}: ${finding.text}`)
}

const census = (file: string, source: string) => unallowed(scanTypography(file, source), TYPOGRAPHY_ALLOWLIST)

describe('typography census scanner', () => {
  it('rejects a literal font size in a feature file with its file and line', () => {
    expect(census('src/features/catalog/components/NewCard.tsx', [
      "import styled from 'styled-components'",
      '',
      'const Meta = styled.span`',
      '  font-size: 11px;',
      '`',
    ].join('\n'))).toEqual(['src/features/catalog/components/NewCard.tsx:4 font-size: font-size: 11px;'])
  })

  it('rejects rem, em and percentage sizes and inline font sizes, and accepts role sizes', () => {
    const source = [
      'const A = styled.p`font-size: 0.75rem;`',
      'const B = styled.p`font-size: 1.2em;`',
      'const C = styled.p`font-size: 90%;`',
      'const D = () => <p style={{ fontSize: 12 }} />',
      'const E = styled.p`font-size: calc(var(--text-body-size) + 1px);`',
    ].join('\n')
    expect(scanTypography('src/features/catalog/View.tsx', source).map(({ line, rule }) => [line, rule])).toEqual([
      [1, 'font-size'], [2, 'font-size'], [3, 'font-size'], [4, 'font-size'],
    ])
  })

  it('allows the dense size in an allowlisted admin table file and rejects it in a catalog file', () => {
    const source = [
      'const HeadRow = styled.tr`',
      "  ${textRole('label', { dense: true })}",
      '`',
      'const Cell = styled.td`',
      '  font-size: var(--text-dense-size);',
      '`',
    ].join('\n')
    expect(census('src/features/admin/pages/ActivityPage.tsx', source)).toEqual([])
    expect(census('src/features/catalog/components/AlbumCard.tsx', source)).toEqual([
      "src/features/catalog/components/AlbumCard.tsx:2 dense-size: ${textRole('label', { dense: true })}",
      'src/features/catalog/components/AlbumCard.tsx:5 dense-size: font-size: var(--text-dense-size);',
    ])
  })

  it('reads the dense option only when it is on', () => {
    expect(scanTypography('src/features/catalog/View.tsx', [
      "const A = styled.p`${textRole('caption', { dense: true })}`",
      "const B = styled.p`${textRole('caption', { dense: false })}`",
      "const C = styled.p`${textRole('caption')}`",
    ].join('\n')).map(({ line, rule }) => [line, rule])).toEqual([[1, 'dense-size']])
  })

  it('rejects weights from 100 to 300 anywhere, including allowlisted files', () => {
    expect(census('src/features/admin/pages/ActivityPage.tsx', 'const T = styled.p`\n  font-weight: 300;\n`')).toEqual([
      'src/features/admin/pages/ActivityPage.tsx:2 font-weight: font-weight: 300;',
    ])
    expect(scanTypography('src/features/catalog/View.tsx', [
      'const A = () => <p style={{ fontWeight: 200 }} />',
      'const B = styled.p`font-weight: ${({ $on }) => ($on ? 600 : 100)};`',
      'const C = styled.p`font-weight: lighter;`',
      'const D = styled.p`font-weight: 400; font-weight: 600;`',
    ].join('\n')).map(({ line }) => line)).toEqual([1, 2, 3])
  })

  it('rejects raw font families and accepts the two family properties', () => {
    expect(census('src/features/catalog/View.tsx', 'const Code = styled.code`\n  font-family: monospace;\n`')).toEqual([
      'src/features/catalog/View.tsx:2 font-family: font-family: monospace;',
    ])
    expect(scanTypography('src/features/catalog/View.tsx', [
      'const A = styled.p`font-family: inherit;`',
      "const B = styled.p`font-family: 'Geist Variable', sans-serif;`",
      "const C = () => <p style={{ fontFamily: 'monospace' }} />",
      'const D = styled.p`font: 12px/1 monospace;`',
      'const E = styled.p`font-family: var(--font-ui);`',
      'const F = styled.p`font-family: var(--font-mono) !important;`',
      "const G = () => <p style={{ fontFamily: 'var(--font-mono)' }} />",
    ].join('\n')).map(({ line, rule }) => [line, rule])).toEqual([
      [1, 'font-family'], [2, 'font-family'], [3, 'font-family'], [4, 'font-family'],
    ])
  })

  it('rejects literal letter spacing and accepts a role tracking token', () => {
    expect(scanTypography('src/features/catalog/View.tsx', [
      'const A = styled.p`text-transform: uppercase; letter-spacing: 0.05em;`',
      "const B = () => <p style={{ letterSpacing: '0.1em' }} />",
      'const C = styled.p`letter-spacing: calc(var(--text-label-tracking) * var(--tracking-scale, 1));`',
    ].join('\n')).map(({ line, rule }) => [line, rule])).toEqual([[1, 'letter-spacing'], [2, 'letter-spacing']])
  })

  it('passes the spectrogram canvas font through its allowlist entry and rejects it elsewhere', () => {
    const source = "    ctx.fillStyle = colors.foreground\n    ctx.font = '10px monospace'\n"
    expect(census('src/features/visualizer/renderers/spectrogram.ts', source)).toEqual([])
    expect(census('src/features/visualizer/renderers/bars.ts', source)).toEqual([
      "src/features/visualizer/renderers/bars.ts:2 canvas-font: ctx.font = '10px monospace'",
    ])
  })

  it('lets an allowlist entry lift only its own rule', () => {
    expect(census('src/features/equalizer/components/EqualizerAnalyzer.tsx', [
      '<text x="52" y="10" fill="currentColor" fontSize="5">In phase</text>',
      'const Label = styled.span`font-size: var(--text-dense-size);`',
    ].join('\n'))).toEqual([
      'src/features/equalizer/components/EqualizerAnalyzer.tsx:2 dense-size: const Label = styled.span`font-size: var(--text-dense-size);`',
    ])
  })

  it('rejects a styled ContentText wrapper given `as` and accepts `element`', () => {
    const source = [
      "import { ContentText } from '@/shared/typography'",
      'const Title = styled(ContentText)`overflow: hidden;`',
      'const BoldTitle = styled(Title)`font-weight: 600;`',
      'export const View = ({ song }) => (',
      '  <div>',
      '    <Title textRole="body" text={song.title} as="h2" />',
      '    <BoldTitle',
      '      textRole="body"',
      '      onClick={() => select(song.id > 0 ? song : null)}',
      '      as="p"',
      '      text={song.title}',
      '    />',
      '    <Title textRole="body" text={song.title} element="h3" />',
      '    <ContentText textRole="body" text={song.title} element="h4" />',
      '  </div>',
      ')',
    ].join('\n')
    expect(scanTypography('src/features/catalog/View.tsx', source).map(({ line, rule }) => [line, rule])).toEqual([
      [6, 'content-text-as'], [7, 'content-text-as'],
    ])
  })

  it('ignores comments that mention typography declarations', () => {
    expect(scanTypography('src/features/catalog/View.tsx', [
      '/** Previously used font-size: 11px and letter-spacing: 0.1em. */',
      'const A = styled.p`',
      '  /* font-family: monospace; */',
      '  color: red; // font-weight: 300',
      '`',
      "const url = 'https://baander.app'; const B = styled.p`font-weight: 300;`",
    ].join('\n')).map(({ line, rule }) => [line, rule])).toEqual([[6, 'font-weight']])
  })

  it('leaves the role implementation files and tests out of the census', () => {
    expect(isCensused('src/shared/theme/mixins.ts')).toBe(false)
    expect(isCensused('src/shared/typography/ContentText.tsx')).toBe(false)
    expect(isCensused('src/features/catalog/components/__tests__/SongRow.test.tsx')).toBe(false)
    expect(isCensused('src/features/catalog/hooks.spec.ts')).toBe(false)
    expect(isCensused('src/features/catalog/components/SongRow.tsx')).toBe(true)
  })
})

const applicationSources = import.meta.glob('../../src/**/*.{ts,tsx,css}', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const censusedSources = Object.entries(applicationSources)
  .map(([path, source]) => [path.replace('../../', ''), source] as const)
  .filter(([file]) => isCensused(file))

describe('application typography census', () => {
  const findings = censusedSources.flatMap(([file, source]) => scanTypography(file, source))

  it('finds no text outside the type roles beyond the allowlist', () => {
    expect(censusedSources.length).toBeGreaterThan(500)
    expect(unallowed(findings, TYPOGRAPHY_ALLOWLIST)).toEqual([])
  })

  it('keeps every allowlist entry in use, unique and explained', () => {
    const files = new Set(censusedSources.map(([file]) => file))
    for (const entry of TYPOGRAPHY_ALLOWLIST) {
      expect(files.has(entry.file), entry.file).toBe(true)
      expect(findings.some((finding) => finding.file === entry.file && finding.rule === EXCEPTION_RULE[entry.kind]), entry.file).toBe(true)
      expect(entry.reason.length, entry.file).toBeGreaterThan(10)
      if (entry.kind === 'admin-table') expect(entry.file, entry.file).toMatch(/^src\/features\/admin\//)
    }
    expect(new Set(TYPOGRAPHY_ALLOWLIST.map((entry) => entry.file)).size).toBe(TYPOGRAPHY_ALLOWLIST.length)
  })
})
