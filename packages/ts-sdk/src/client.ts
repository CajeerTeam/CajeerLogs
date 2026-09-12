import type { LogEvent } from './types'

export class CajeerLogsClient {
  constructor(private readonly baseUrl: string, private readonly token: string) {}

  async ingest(event: LogEvent | { events: LogEvent[] }) {
    const response = await fetch(`${this.baseUrl}/api/v1/ingest`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.token}`
      },
      body: JSON.stringify(event)
    })

    if (!response.ok) {
      throw new Error(`CajeerLogs ingest failed: ${response.status}`)
    }

    return response.json()
  }
}
