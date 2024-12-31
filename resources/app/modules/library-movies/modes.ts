export interface MovieResource {
  title: string;
  ratingScores: RatingScore[];
  status: 'OK' | 'SCANNING' | 'PATH_NOT_FOUND';
  runtime?: string;
  language?: string;
  poster?: string;
  year?: number;
  summary?: string;
  genres?: string[];
  actors?: Person[];
}

export interface RatingScore {
  provider: string;
  score: string;
}

export interface Person {
  name: string;
  image?: string;
}