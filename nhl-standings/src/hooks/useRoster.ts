import { useState, useEffect } from 'react'
import type { RosterResponse } from '../types/roster'

// Custom Hook: Lädt den Roster eines Teams anhand des Kürzels (z.B. "BOS", "TOR").
// Fetcht neu wenn sich teamAbbrev ändert.
// AbortController bricht laufende Requests ab wenn schnell zwischen Teams gewechselt wird.
export function useRoster(teamAbbrev: string) {
    const [roster, setRoster] = useState<RosterResponse | null>(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState<string | null>(null)

    useEffect(() => {
        const controller = new AbortController()
        setLoading(true)
        setError(null)

        async function fetchRoster() {
            try {
                const url = `/api/nhl/roster/${teamAbbrev}`
                const response = await fetch(url, {
                    signal: controller.signal,
                })

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`)
                }

                const data: RosterResponse = await response.json()
                setRoster(data)
            } catch (err) {
                if (err instanceof Error && err.name !== 'AbortError') {
                    setError(err.message)
                }
            } finally {
                setLoading(false)
            }
        }

        fetchRoster()

        return () => controller.abort()
    }, [teamAbbrev])

    return { roster, loading, error }
}
