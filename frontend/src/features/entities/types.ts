export interface MergeCandidateEntity {
  id: string
  type: string
  layer: string
  label: string
  payload: Record<string, unknown>
}

export interface MergeCandidate {
  id: string
  similarity: number
  method: string
  entity_a: MergeCandidateEntity
  entity_b: MergeCandidateEntity
}
