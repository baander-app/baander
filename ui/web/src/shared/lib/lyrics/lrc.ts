export interface Lyric {
  timestamp: number
  content: string
}

export interface Info {
  [key: string]: string
}

const TAGS_REGEXP = /^(\[.+])+/
const INFO_REGEXP = /^\s*(\w+)\s*:(.*)$/
const TIME_REGEXP = /^\s*(\d+)\s*:\s*(\d+(\s*[.:]\s*\d+)?)\s*$/

interface InvalidLine { type: 'INVALID' }
interface TimeLine { type: 'TIME'; timestamps: number[]; content: string }
interface InfoLine { type: 'INFO'; key: string; value: string }

function parseTags(line: string): null | [string[], string] {
  line = line.trim()
  const matches = TAGS_REGEXP.exec(line)
  if (matches === null) return null
  const tag = matches[0]
  const content = line.slice(tag.length)
  return [tag.slice(1, -1).split(/]\s*\[/), content]
}

function parseTime(tags: string[], content: string): TimeLine {
  const timestamps: number[] = []

  for (const tag of tags) {
    const matches = TIME_REGEXP.exec(tag)
    if (!matches) continue

    const minutes = parseFloat(matches[1])
    const seconds = parseFloat(matches[2].replace(/\s+/g, '').replace(':', '.'))
    timestamps.push(minutes * 60 + seconds)
  }

  return { type: 'TIME', timestamps, content: content.trim() }
}

function parseInfo(tag: string): InfoLine {
  const matches = INFO_REGEXP.exec(tag)
  if (!matches) return { type: 'INFO', key: '', value: '' }
  return { type: 'INFO', key: matches[1].trim(), value: matches[2].trim() }
}

function parseLine(line: string): InvalidLine | TimeLine | InfoLine {
  const parsedTags = parseTags(line)
  try {
    if (parsedTags) {
      const [tags, content] = parsedTags
      if (TIME_REGEXP.test(tags[0])) {
        return parseTime(tags, content)
      }
      return parseInfo(tags[0])
    }
    return { type: 'INVALID' }
  } catch {
    return { type: 'INVALID' }
  }
}

function padZero(num: number | string, size: number = 2): string {
  let str = num.toString()
  while (str.split('.')[0].length < size) str = '0' + str
  return str
}

export function timestampToString(timestamp: number): string {
  return `${padZero(Math.floor(timestamp / 60))}:${padZero((timestamp % 60).toFixed(2))}`
}

export class Lrc {
  info: Info = {}
  lyrics: Lyric[] = []

  static parse(text: string): Lrc {
    const lyrics: Lyric[] = []
    const info: Info = {}

    text.split(/\r\n|[\n\r]/g).forEach((line) => {
      const parsedLine = parseLine(line)
      switch (parsedLine.type) {
        case 'INFO':
          info[parsedLine.key] = parsedLine.value
          break
        case 'TIME':
          for (const ts of parsedLine.timestamps) {
            lyrics.push({ timestamp: ts, content: parsedLine.content })
          }
          break
      }
    })

    const lrc = new Lrc()
    lrc.lyrics = lyrics
    lrc.info = info
    return lrc
  }

  offset(offsetTime: number): void {
    for (const lyric of this.lyrics) {
      lyric.timestamp += offsetTime
      if (lyric.timestamp < 0) lyric.timestamp = 0
    }
  }

  clone(): Lrc {
    const c = new Lrc()
    c.info = { ...this.info }
    c.lyrics = this.lyrics.map((l) => ({ ...l }))
    return c
  }
}
