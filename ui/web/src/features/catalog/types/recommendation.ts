export interface Recommendation {
  id: string
  name: string
  source_type: string
  source_id: string
  target_type: string
  target_id: string
  score: number
  position: number
  user_id: string | null
  created_at: string
  updated_at: string
  // Enriched display fields (optional for backward compatibility)
  sourceName?: string | null
  targetTitle?: string | null
  targetArtistName?: string | null
  coverImageUrl?: string | null
}

export interface RecommendationCluster {
  sourceId: string
  sourceType: string
  sourceName: string
  items: Recommendation[]
}
