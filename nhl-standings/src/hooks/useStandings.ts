import { useState, useEffect } from 'react'
import type { TeamStanding } from '../types/standings'

const API_URL = '/api/nhl/standings'

// Fetches once on mount — filtering happens client-side on the cached array.
export function useStandings() {
    const [standings, setStandings] = useState<TeamStanding[]>([])
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState<string | null>(null)

    useEffect(() => {
        const controller = new AbortController()

        async function fetchStandings() {
            try {
                const response = await fetch(API_URL, {
                    signal: controller.signal,
                })

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`)
                }

                const data = await response.json()
                setStandings(data.standings)
            } catch (err) {
                if (err instanceof Error && err.name !== 'AbortError') {
                    setError(err.message)
                }
            } finally {
                setLoading(false)
            }
        }

        fetchStandings()

        return () => controller.abort()
    }, [])

    return { standings, loading, error }
}
