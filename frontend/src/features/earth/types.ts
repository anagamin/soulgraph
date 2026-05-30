export type EarthEntityType = 'event' | 'epoch' | 'person' | 'place' | 'relationship'

export interface EarthTemporal {
  approx_year: number | null
  occurred_at: string | null
  life_period: string | null
  sort_key: number
  has_date: boolean
  display: string
}

export interface EarthEntity {
  id: string
  type: EarthEntityType
  label: string
  payload: Record<string, unknown>
  confidence?: number
  valid_from?: string
  temporal: EarthTemporal
  related_count: number
}

export interface EarthEdge {
  id: string
  source: string
  target: string
  type: string
  confidence: number
}

export interface EarthTimelineGroup {
  label: string
  sort_key: number
  items: EarthEntity[]
}

export interface EarthCatalog {
  events: EarthEntity[]
  epochs: EarthEntity[]
  people: EarthEntity[]
  places: EarthEntity[]
  relationships: EarthEntity[]
  edges: EarthEdge[]
  timeline: EarthTimelineGroup[]
}

export interface EarthPhrase {
  id: string
  content: string
  created_at?: string
}

export interface EarthRelatedEntity {
  relation_id: string
  relation_type: string
  direction: 'outgoing' | 'incoming'
  entity: EarthEntity
}

export interface EarthEntityDetail {
  entity: EarthEntity
  summary: string | null
  related: EarthRelatedEntity[]
  phrases: EarthPhrase[]
}

export const ENTITY_TYPE_LABELS: Record<EarthEntityType, string> = {
  event: 'Событие',
  epoch: 'Эпоха',
  person: 'Человек',
  place: 'Место',
  relationship: 'Отношение',
}

export const RELATION_TYPE_LABELS: Record<string, string> = {
  participated_in: 'участвовал в',
  located_in: 'находится в',
  involves: 'связан с',
  part_of: 'часть',
  associated_with: 'ассоциируется с',
  causes: 'вызывает',
  triggers: 'триггерит',
  evolves_into: 'эволюционирует в',
}

export type EarthTab = 'timeline' | 'events' | 'people' | 'places' | 'relationships'
