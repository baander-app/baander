/**
 * Regular expression to match sequences of tags enclosed in square brackets.
 * This regex will capture one or more tags that start with an opening square
 * bracket '[' and end with a closing square bracket ']', with any characters
 * inside the brackets.
 *
 * Matches:
 * - [tag1]
 * - [tag1][tag2]
 * - [tag1][tag2][tag3]
 *
 * Does not match:
 * - [tag1
 * - tag1]
 * - tag1
 *
 * @type {RegExp}
 */
export const TAGS_REGEXP: RegExp = /^(\[.+])+/;
/**
 * Regular expression to capture and parse information from strings.
 *
 * This pattern matches and captures two groups from the input string:
 * - The first group captures one or more word characters (alphanumeric and underscores).
 * - The second group captures any characters after a colon (':') and optional surrounding whitespace.
 * - The pattern expects the string to start with optional whitespace, followed by the word characters, a colon, and then any remaining characters.
 *
 * This can be useful for parsing labels and their corresponding values from formatted strings.
 */
export const INFO_REGEXP = /^\s*(\w+)\s*:(.*)$/;
/**
 * Regular expression for matching time strings.
 *
 * This regular expression can match and extract components of a time string in the following format:
 * - One or more digits for the hour component.
 * - A colon (:) followed by one or more digits for the minute component.
 * - An optional period (.) or colon (:) followed by one or more digits for fractional minutes.
 * - Optional surrounding whitespace.
 *
 * Captured groups:
 * - Group 1: Hours component (one or more digits).
 * - Group 2: Entire minute and fractional minute component.
 * - Group 3: Fractional minute component, if any, including the separating character.
 */
export const TIME_REGEXP = /^\s*(\d+)\s*:\s*(\d+(\s*[.:]\s*\d+)?)\s*$/;

// Enumeration for line types
export enum LineType {
  INVALID = 'INVALID',
  INFO = 'INFO',
  TIME = 'TIME',
}

// Interfaces to define the structure of different line types
export interface InvalidLine {
  type: LineType.INVALID;
}

export interface TimeLine {
  type: LineType.TIME;
  timestamps: number[];
  content: string;
}

export interface InfoLine {
  type: LineType.INFO;
  key: string;
  value: string;
}

/**
 * Parses a line to extract tags and remaining content.
 *
 * @param {string} line - The input string to parse for tags.
 * @return {null | [string[], string]} An array containing the list of tags and the remaining content or null if no tags are found.
 */
export function parseTags(line: string): null | [string[], string] {
  line = line.trim();
  const matches = TAGS_REGEXP.exec(line);
  if (matches === null) {
    return null;
  }
  const tag = matches[0];
  const content = line.slice(tag.length);
  return [tag.slice(1, -1).split(/]\s*\[/), content];
}

/**
 * Parses the time tags and content to generate a TimeLine object.
 * Subtracts animation time to ensure lyrics shows up at the correct time.
 *
 * @param {string[]} tags - An array of time tags to be parsed.
 * @param {string} content - The content associated with the time tags.
 * @return {TimeLine} - The parsed TimeLine object containing type, timestamps, and content.
 */
export function parseTime(tags: string[], content: string): TimeLine {
  interface ExtendedTimestamp {
    start: number;
    end: number;
  }

  const timestamps: ExtendedTimestamp[] = [];

  tags.forEach((tag) => {
    const matches = TIME_REGEXP.exec(tag);
    if (!matches) {
      throw new Error(`Invalid time format in tag: ${tag}`);
    }

    const animationTime = 0.080;

    const minutes = parseFloat(matches[1]);
    let seconds = parseFloat(matches[2].replace(/\s+/g, '').replace(':', '.'));
    let start = minutes * 60 + seconds;
    let end = start;

    // Subtract animationTime from the start
    start -= animationTime;
    // Add animationTime to the end
    end += animationTime;

    // Ensure the adjusted timestamps are not negative
    if (start < 0) {
      start = 0;
    }

    timestamps.push({ start, end });
  });

  // Flatten the timestamps structure if needed: e.g., [1.985, 2.015, ...]
  const flatTimestamps: number[] = timestamps.reduce(
    (acc: number[], curr) => acc.concat([curr.start, curr.end]),
    []
  );

  return {
    type: LineType.TIME,
    timestamps: flatTimestamps,
    content: content.trim(),
  };
}

/**
 * Parses the given info tag and extracts key and value components.
 *
 * @param {string} tag - The info tag to be parsed.
 * @returns {InfoLine} An object containing the type, key, and value extracted from the tag.
 * @throws {Error} If the tag format is invalid.
 */
export function parseInfo(tag: string): InfoLine {
  const matches = INFO_REGEXP.exec(tag);
  if (!matches) {
    throw new Error(`Invalid info format in tag: ${tag}`);
  }
  return {
    type: LineType.INFO,
    key: matches[1].trim(),
    value: matches[2].trim(),
  };
}

/**
 * Parses a line of text and returns an object representing either information,
 * time data, or an invalid line.
 *
 * @param {string} line - The line of text to be parsed.
 * @return {InfoLine | TimeLine | InvalidLine} An object representing the parsed line.
 */
export function parseLine(line: string): InfoLine | TimeLine | InvalidLine {
  const parsedTags = parseTags(line);
  try {
    if (parsedTags) {
      const [tags, content] = parsedTags;
      if (TIME_REGEXP.test(tags[0])) {
        return parseTime(tags, content);
      } else {
        return parseInfo(tags[0]);
      }
    }
    return { type: LineType.INVALID };
  } catch (error) {
    console.error('Error parsing line:', error);
    return { type: LineType.INVALID };
  }
}