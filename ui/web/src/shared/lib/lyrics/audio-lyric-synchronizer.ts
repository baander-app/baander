import {Lrc} from './lrc'

export class AudioLyricSynchronizer {
  private _currentIndex = -1
  lrc: Lrc
  defaultOffset: number

  constructor(lrc: Lrc = new Lrc(), defaultOffset = -0.15) {
    this.defaultOffset = defaultOffset
    this.lrc = lrc.clone()
    this.applyOffset()
    this.sort()
  }

  private applyOffset(): void {
    let offsetMs = this.defaultOffset
    if ('offset' in this.lrc.info) {
      const parsed = parseInt(this.lrc.info.offset)
      if (!isNaN(parsed)) offsetMs = parsed / 1000
      delete this.lrc.info.offset
    }
    this.lrc.offset(offsetMs)
  }

  private sort(): void {
    this.lrc.lyrics.sort((a, b) => a.timestamp - b.timestamp)
  }

  timeUpdate(timestamp: number): void {
    this._currentIndex = this._findIndex(timestamp, this._currentIndex)
  }

  private _findIndex(timestamp: number, startIndex: number): number {
    const lyrics = this.lrc.lyrics
    if (lyrics.length === 0) return -1

    // Clamp startIndex
    if (startIndex >= lyrics.length) startIndex = lyrics.length - 1
    if (startIndex < -1) startIndex = -1

    const curFront = startIndex === -1 ? Number.NEGATIVE_INFINITY : lyrics[startIndex].timestamp
    const curBack =
      startIndex === lyrics.length - 1 ? Number.POSITIVE_INFINITY : lyrics[startIndex + 1].timestamp

    if (timestamp < curFront) return this._findIndex(timestamp, startIndex - 1)
    if (timestamp === curBack) return curBack === Number.POSITIVE_INFINITY ? startIndex : startIndex + 1
    if (timestamp > curBack) return this._findIndex(timestamp, startIndex + 1)
    return startIndex
  }

  curIndex(): number {
    return this._currentIndex
  }

  getLyric(index: number = this.curIndex()) {
    if (index >= 0 && index <= this.lrc.lyrics.length - 1) {
      return this.lrc.lyrics[index]
    }
    return null
  }

  current() {
    return this.getLyric()
  }

  next() {
    return this.getLyric(this._currentIndex + 1)
  }

  getLyrics() {
    return this.lrc.lyrics
  }
}
