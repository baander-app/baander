export interface Album {
  title: string;
  artist: string;
  cover?: string;
  genre?: string;
  releaseYear?: number;
}

export const albumMocks: Album[] = [
  { title: 'Elvis Reborn - New Mono to Stero Mixes', artist: 'Elvis Presley', genre: 'Rock', releaseYear: 2005 },
  { title: 'The Beatles - 1962-1966', artist: 'The Beatles', genre: 'Rock', releaseYear: 1973 },
  { title: 'Studio Session', artist: 'Headhunterz', genre: 'Electronic', releaseYear: 2010 },
  { title: 'Thriller', artist: 'Michael Jackson', genre: 'Pop', releaseYear: 1982 },
  { title: 'Back in Black', artist: 'AC/DC', genre: 'Rock', releaseYear: 1980 },
  { title: 'The Dark Side of the Moon', artist: 'Pink Floyd', genre: 'Rock', releaseYear: 1973 },
  { title: 'The Bodyguard', artist: 'Whitney Houston', genre: 'R&B', releaseYear: 1992 },
  { title: 'Bat Out of Hell', artist: 'Meat Loaf', genre: 'Rock', releaseYear: 1977 },
  { title: 'Their Greatest Hits (1971–1975)', artist: 'Eagles', genre: 'Rock', releaseYear: 1976 },
  { title: 'Saturday Night Fever', artist: 'Bee Gees', genre: 'Disco', releaseYear: 1977 },
  { title: 'Rumours', artist: 'Fleetwood Mac', genre: 'Rock', releaseYear: 1977 },
  { title: 'Grease', artist: 'Various Artists', genre: 'Soundtrack', releaseYear: 1978 },
  { title: '21', artist: 'Adele', genre: 'Pop', releaseYear: 2011 },
  { title: 'Supernatural', artist: 'Santana', genre: 'Rock', releaseYear: 1999 },
  { title: 'Human Clay', artist: 'Creed', genre: 'Rock', releaseYear: 1999 },
  { title: 'Come Away with Me', artist: 'Norah Jones', genre: 'Jazz', releaseYear: 2002 },
  { title: 'Legend', artist: 'Bob Marley & The Wailers', genre: 'Reggae', releaseYear: 1984 },
  { title: 'Jagged Little Pill', artist: 'Alanis Morissette', genre: 'Alternative Rock', releaseYear: 1995 },
  { title: 'Doctor Zhivago', artist: 'Original Soundtrack', genre: 'Soundtrack', releaseYear: 1965 },
  { title: 'The Lion King', artist: 'Original Soundtrack', genre: 'Soundtrack', releaseYear: 1994 },
  { title: 'Millennium', artist: 'Backstreet Boys', genre: 'Pop', releaseYear: 1999 },
  { title: 'Dirty Dancing', artist: 'Original Soundtrack', genre: 'Soundtrack', releaseYear: 1987 },
  { title: 'Titanic', artist: 'James Horner', genre: 'Soundtrack', releaseYear: 1997 },
  { title: 'Fallen', artist: 'Evanescence', genre: 'Rock', releaseYear: 2003 },
  { title: 'Led Zeppelin IV', artist: 'Led Zeppelin', genre: 'Rock', releaseYear: 1971 },
  { title: 'Bad', artist: 'Michael Jackson', genre: 'Pop', releaseYear: 1987 },
  { title: '1984 (Van Halen)', artist: 'Van Halen', genre: 'Rock', releaseYear: 1984 },
  { title: 'Slippery When Wet', artist: 'Bon Jovi', genre: 'Rock', releaseYear: 1986 },
  { title: 'Dookie', artist: 'Green Day', genre: 'Punk Rock', releaseYear: 1994 },
  { title: 'The Joshua Tree', artist: 'U2', genre: 'Rock', releaseYear: 1987 },
]

export interface Track {
  title: string;
  number: number;
  duration: string;
}

export const trackMocks: Track[] = [
  { title: 'Beat It', number: 1, duration: '4:18' },
  { title: 'Billie Jean', number: 2, duration: '4:54' },
  { title: 'Black Dog', number: 3, duration: '4:55' },
  { title: 'Stairway to Heaven', number: 4, duration: '8:02' },
  { title: 'Bohemian Rhapsody', number: 5, duration: '5:55' },
  { title: 'Don\'t Stop Believin\'', number: 6, duration: '4:09' },
  { title: 'Hotel California', number: 7, duration: '6:30' },
  { title: 'Imagine', number: 8, duration: '3:01' },
  { title: 'Like a Rolling Stone', number: 9, duration: '6:09' },
  { title: 'Smells Like Teen Spirit', number: 10, duration: '5:01' },
  { title: 'What\'s Going On', number: 11, duration: '3:52' },
  { title: 'My Generation', number: 12, duration: '3:18' },
]

export interface Artist {
  name: string;
  imgSrc?: string;
}

export const artistMocks: Artist[] = [
  { name: 'Elvis Presley' },
  { name: 'The Beatles' },
  { name: 'Headhunterz' },
  { name: 'Michael Jackson' },
  { name: 'AC/DC' },
  { name: 'Pink Floyd' },
  { name: 'Whitney Houston' },
  { name: 'Meat Loaf' },
  { name: 'Eagles' },
  { name: 'Bee Gees' },
  { name: 'Fleetwood Mac' },
  { name: 'Adele' },
  { name: 'Santana' },
  { name: 'Creed' },
  { name: 'Norah Jones' },
  { name: 'Bob Marley & The Wailers' },
  { name: 'Alanis Morissette' },
]
