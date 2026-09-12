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
