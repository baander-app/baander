export const attributeDefinitions: { [key: string]: string } = {
  id: 'The unique identifier for this entity.',
  name: 'The name of the entity.',
  sortName: 'The name by which the artist is sorted.',
  gender: "The gender of the artist. Possible values are 'male', 'female', or 'other'.",
  country: 'The country associated with the artist.',
  disambiguation: 'Additional information to disambiguate the artist from others with similar names.',
  title: 'The title of the release.',
  releaseDate: 'The release date of the album in ISO 8601 format.',
  artist: 'The artist or group who recorded the album.',
  barcode: 'The barcode of the release.',
  status: 'The status of the release (e.g., official, promotion, bootleg).',
  packaging: 'The type of packaging used for the release.',
  language: 'The language in which the release is primarily recorded.',
  trackID: 'The unique identifier for the track.',
  trackNumber: 'The track number on the release.',
  trackTitle: 'The title of the track.',
  trackLength: 'The duration of the track in milliseconds.',
  labelID: 'The unique identifier for the label.',
  labelName: 'The name of the label.',
  labelCode: 'The label code (a unique identifier assigned to a record label).',
  recordingID: 'The unique identifier for the recording.',
  recordingTitle: 'The title of the recording.',
  recordingLength: 'The duration of the recording in milliseconds.',
  workID: 'The unique identifier for the work.',
  workTitle: 'The title of the work.',
  workType: 'The type of work (e.g., song, symphony).',
  workLanguage: 'The language of the work.',
  url: 'The URL related to the entity.',
  urlType: 'The type of relationship the URL has to the entity (e.g., official homepage, discography).',
  eventID: 'The unique identifier for the event.',
  eventDate: 'The date of the event in ISO 8601 format.',
  eventName: 'The name of the event.',
  eventType: 'The type of event (e.g., concert, festival).',
  eventLocation: 'The location of the event.',
  areaID: 'The unique identifier for the area.',
  areaName: 'The name of the area (e.g., a country, city).',
  areaType: 'The type of area (e.g., country, city, subdivision).',
  genreID: 'The unique identifier for the genre.',
  genreName: 'The name of the genre.',
  comment: 'A comment or annotation for the entity.',
  alias: 'An alternative name or spelling for the entity.',
  beginDate: 'The date when the entity began.',
  endDate: 'The date when the entity ended.',
  lifespan: 'The lifespan of the entity (begin and end dates).',
};