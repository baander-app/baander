import { Lrc } from './lrc';

export class AudioLyricSynchronizer {
  useOffset: boolean;
  defaultOffset: number;
  private _currentIndex: number;
  lrc!: Lrc;

  constructor(lrc: Lrc = new Lrc(), useOffset: boolean = true, defaultOffset: number = -150) {
    this.useOffset = useOffset;
    this.defaultOffset = defaultOffset; // Default offset in milliseconds
    this._currentIndex = -1;
    this.setLrc(lrc);
  }

  /**
   * Sets the LRC and updates the lyrics and offset.
   * @param lrc - The LRC object to set.
   */
  setLrc(lrc: Lrc): void {
    this.lrc = lrc.clone();
    this.lrcUpdate();
  }

  /**
   * Updates LRC by aligning the offset and sorting lyrics.
   */
  lrcUpdate(): void {
    if (this.useOffset) {
      this._offsetAlign();
    }
    this._sort();
  }

  /**
   * Aligns the offset if it exists in LRC info, otherwise uses the default offset.
   */
  private _offsetAlign(): void {
    let offsetMs: number;

    if ('offset' in this.lrc.info) {
      offsetMs = parseInt(this.lrc.info.offset);
      if (isNaN(offsetMs)) {
        offsetMs = this.defaultOffset;
      }
      delete this.lrc.info.offset;
    } else {
      offsetMs = this.defaultOffset;
    }

    // Convert milliseconds to seconds for the lrc.offset() method
    this.lrc.offset(offsetMs / 1000);
  }

  /**
   * Sets a new default offset value
   * @param offsetMs - The new default offset in milliseconds
   */
  setDefaultOffset(offsetMs: number): void {
    this.defaultOffset = offsetMs;
    if (this.useOffset) {
      this.lrcUpdate();
    }
  }

  /**
   * Sorts the lyrics by their timestamp.
   */
  private _sort(): void {
    this.lrc.lyrics.sort((a, b) => a.timestamp - b.timestamp);
  }

  /**
   * Updates the current index based on the given timestamp.
   * @param timestamp - The current timestamp of the audio.
   */
  timeUpdate(timestamp: number): void {
    if (this._currentIndex >= this.lrc.lyrics.length) {
      this._currentIndex = this.lrc.lyrics.length - 1;
    } else if (this._currentIndex < -1) {
      this._currentIndex = -1;
    }
    this._currentIndex = this._findIndex(timestamp, this._currentIndex);
  }

  /**
   * Finds the appropriate index for the given timestamp.
   * @param timestamp - The current timestamp of the audio.
   * @param startIndex - The starting index for the search.
   * @returns The index of the lyric that corresponds to the timestamp.
   */
  private _findIndex(timestamp: number, startIndex: number): number {
    const curFrontTimestamp =
      startIndex === -1 ? Number.NEGATIVE_INFINITY : this.lrc.lyrics[startIndex].timestamp;

    const curBackTimestamp =
      startIndex === this.lrc.lyrics.length - 1 ? Number.POSITIVE_INFINITY : this.lrc.lyrics[startIndex + 1].timestamp;

    if (timestamp < curFrontTimestamp) {
      return this._findIndex(timestamp, startIndex - 1);
    } else if (timestamp === curBackTimestamp) {
      return curBackTimestamp === Number.POSITIVE_INFINITY ? startIndex : startIndex + 1;
    } else if (timestamp > curBackTimestamp) {
      return this._findIndex(timestamp, startIndex + 1);
    } else {
      return startIndex;
    }
  }

  /**
   * Gets the LRC info.
   * @returns An object containing LRC info.
   */
  getInfo() {
    return this.lrc.info;
  }

  /**
   * Gets all the lyrics.
   * @returns An array containing all the lyrics.
   */
  getLyrics() {
    return this.lrc.lyrics;
  }

  /**
   * Gets the lyric at the specified index.
   * @param index - The index of the lyric to retrieve. Defaults to the current index.
   * @returns The lyric at the specified index.
   * @throws Error if the index is out of range.
   */
  getLyric(index: number = this.curIndex()) {
    if (index >= 0 && index <= this.lrc.lyrics.length - 1) {
      return this.lrc.lyrics[index];
    }

    return null;
  }

  /**
   * Gets the current index.
   */
  curIndex(): number {
    return this._currentIndex;
  }

  /**
   * Gets the current lyric.
   */
  current() {
    return this.getLyric();
  }

  next() {
    return this.getLyric(this._currentIndex + 1);
  }
}