import { parseLine, LineType } from './line-parser';

export interface Lyric {
  timestamp: number;
  content: string;
}

export interface CombineLyric {
  timestamps: number[];
  content: string;
}

export type Info = Record<string, string>;

/**
 * Pads a number with leading zeros to meet the specified width.
 * @param num - The number to pad.
 * @param size - The desired width of the string.
 * @returns A zero-padded string representation of the number.
 */
export function padZero(num: number | string, size: number = 2): string {
  let str = num.toString();
  while (str.split('.')[0].length < size) str = '0' + str;
  return str;
}

/**
 * Converts a timestamp to a string in the format of MM:SS.ss.
 * @example
 * Lrc.timestampToString(143.54)
 * // return '02:23.54':
 * @param timestamp - The timestamp in seconds.
 * @returns A string representation of the timestamp.
 */
export function timestampToString(timestamp: number): string {
  return `${padZero(Math.floor(timestamp / 60))}:${padZero(
    (timestamp % 60).toFixed(2),
  )}`;
}

export type LineFormat = '\r\n' | '\r' | '\n';

export interface ToStringOptions {
  combine: boolean;
  sort: boolean;
  lineFormat: LineFormat;
}

export class Lrc {
  info: Info = {};
  lyrics: Lyric[] = [];

  /**
   * Parses LRC formatted text and creates an Lrc object.
   * @param text - The LRC text to parse.
   * @returns An instance of the Lrc class.
   */
  static parse(text: string): Lrc {
    const lyrics: Lyric[] = [];
    const info: Info = {};
    text.split(/\r\n|[\n\r]/g).forEach((line) => {
      const parsedLine = parseLine(line);
      switch (parsedLine.type) {
        case LineType.INFO:
          info[parsedLine.key] = parsedLine.value;
          break;
        case LineType.TIME:
          parsedLine.timestamps.forEach((timestamp) => {
            lyrics.push({ timestamp, content: parsedLine.content });
          });
          break;
        default:
          // Ignore invalid lines
          break;
      }
    });

    const lrc = new Lrc();
    lrc.lyrics = lyrics;
    lrc.info = info;
    return lrc;
  }

  /**
   * Adjusts the timestamps of the lyrics by a specified offset.
   * @param offsetTime - The time in seconds to offset.
   */
  offset(offsetTime: number): void {
    this.lyrics.forEach((lyric) => {
      lyric.timestamp += offsetTime;
      if (lyric.timestamp < 0) {
        lyric.timestamp = 0;
      }
    });
  }

  /**
   * Clones the Lrc object, creating a deep copy of it.
   * @returns A new instance of the Lrc class.
   */
  clone(): Lrc {
    const lrcClone = new Lrc();
    lrcClone.info = { ...this.info };
    lrcClone.lyrics = this.lyrics.map((lyric) => ({ ...lyric }));
    return lrcClone;
  }

  /**
   * Converts the Lrc object to a formatted LRC string.
   * @param opts - Options to format the output string.
   * @returns A formatted string representing the LRC.
   */
  toString(opts: Partial<ToStringOptions> = {}): string {
    opts.combine = opts.combine ?? true;
    opts.lineFormat = opts.lineFormat ?? '\r\n';
    opts.sort = opts.sort ?? true;

    const lines: string[] = [];
    const lyricsMap: Record<string, number[]> = {};
    const lyricsList: CombineLyric[] = [];

    // Add info lines
    for (const key in this.info) {
      lines.push(`[${key}:${this.info[key]}]`);
    }

    if (opts.combine) {
      // Combine lyrics with the same content
      this.lyrics.forEach((lyric) => {
        if (lyricsMap[lyric.content]) {
          lyricsMap[lyric.content].push(lyric.timestamp);
        } else {
          lyricsMap[lyric.content] = [lyric.timestamp];
        }
      });

      // Sort and prepare combined lyrics list
      for (const content in lyricsMap) {
        if (opts.sort) {
          lyricsMap[content].sort((a, b) => a - b);
        }
        lyricsList.push({ timestamps: lyricsMap[content], content });
      }

      if (opts.sort) {
        lyricsList.sort((a, b) => a.timestamps[0] - b.timestamps[0]);
      }

      // Generate combined lyrics lines
      lyricsList.forEach((lyric) => {
        lines.push(
          `[${lyric.timestamps
                   .map(timestampToString)
                   .join('][')}]${lyric.content || ''}`
        );
      });
    } else {
      // Generate individual lyrics lines
      this.lyrics.forEach((lyric) => {
        lines.push(`[${timestampToString(lyric.timestamp)}]${lyric.content}`);
      });
    }

    return lines.join(opts.lineFormat);
  }
}