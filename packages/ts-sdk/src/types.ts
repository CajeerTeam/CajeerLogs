export type LogLevel = 'debug' | 'info' | 'notice' | 'warning' | 'error' | 'critical' | 'alert' | 'emergency'

export interface LogEvent {
  timestamp: string
  source: string
  level: LogLevel
  message: string
  service?: string
  host?: string
  context?: Record<string, unknown>
  trace_id?: string
  request_id?: string
  tags?: string[]
}

export interface IngestReceipt {
  accepted: true
  received: number
  stored: number
  rejected: 0
  event_ids: string[]
  replayed: boolean
}


/** HTTP 202 means accepted durably, NOT that the events have been inserted. */
export interface AsyncDeliveryReceipt {
  accepted: true
  received: number
  job_id: string
  status: 'pending' | 'running' | 'completed' | 'dead' | 'cancelled'
  replayed: boolean
}

export interface JobStatus {
  id: string
  status: 'pending' | 'running' | 'completed' | 'dead' | 'cancelled'
  attempts: number
  max_attempts: number
  result: { event_ids: string[]; stored: number } | null
}
