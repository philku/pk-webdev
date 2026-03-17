import { useState, useMemo } from 'react'
import type { ConferenceFilter } from './types/standings'
import { useStandings } from './hooks/useStandings'
import { ConferenceFilter as FilterButtons } from './components/ConferenceFilter'
import { StandingsTable } from './components/StandingsTable'
import { TeamDetail } from './components/TeamDetail'
import { LoadingSpinner } from './components/LoadingSpinner'
import { ErrorMessage } from './components/ErrorMessage'

// Root-Komponente: Zeigt entweder die Standings-Tabelle oder eine Team-Detail-Ansicht.
// Der selectedTeam-State steuert welche Ansicht aktiv ist.
// Standings werden einmal geladen und client-seitig gefiltert (kein Re-Fetch bei Filter-Wechsel).
export function App() {
    const { standings, loading, error } = useStandings()
    const [selectedTeam, setSelectedTeam] = useState<string | null>(null)
    const [conference, setConference] = useState<ConferenceFilter>('all')
    const [division, setDivision] = useState('all')

    // Client-seitige Filterung auf dem gecachten Standings-Array.
    // useMemo verhindert unnötiges Neu-Filtern bei jedem Render.
    const filtered = useMemo(() => {
        let result = standings
        if (conference !== 'all') {
            result = result.filter((t) => t.conferenceName === conference)
        }
        if (division !== 'all') {
            result = result.filter((t) => t.divisionName === division)
        }
        return result
    }, [standings, conference, division])

    // Team-Detail: Daten aus dem bereits geladenen Standings-Array holen
    const selectedTeamData = selectedTeam
        ? standings.find((t) => t.teamAbbrev.default === selectedTeam)
        : null

    if (loading) return <LoadingSpinner />
    if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />

    // Team-Detail-Ansicht
    if (selectedTeamData) {
        return (
            <TeamDetail
                team={selectedTeamData}
                onBack={() => setSelectedTeam(null)}
            />
        )
    }

    // Standings-Ansicht
    return (
        <div>
            <div className="mb-4">
                <FilterButtons
                    conference={conference}
                    division={division}
                    onConferenceChange={setConference}
                    onDivisionChange={setDivision}
                />
            </div>
            <StandingsTable
                standings={filtered}
                onSelectTeam={setSelectedTeam}
            />
        </div>
    )
}
