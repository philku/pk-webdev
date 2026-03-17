import { useState, useEffect } from 'react'
import type { TeamStanding } from '../types/standings'

const API_URL = '/api/nhl/standings'

// Custom Hook: Lädt die NHL-Standings einmal beim Mount.
// Gibt typisierte Daten, Loading-State und Fehler zurück.
// Filter (Conference, Division) passiert client-seitig auf dem gecachten Array —
// kein neuer API-Call nötig.
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

        // Cleanup: laufenden Request abbrechen wenn die Komponente unmountet
        return () => controller.abort()
    }, [])

    return { standings, loading, error }
}
