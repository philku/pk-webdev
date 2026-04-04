import { useState, useMemo } from 'react'
import { Routes, Route } from 'react-router-dom'
import type { ConferenceFilter } from './types/standings'
import { useStandings } from './hooks/useStandings'
import { ConferenceFilter as FilterButtons } from './components/ConferenceFilter'
import { StandingsTable } from './components/StandingsTable'
import { TeamDetail } from './components/TeamDetail'
import { TeamSearch } from './components/TeamSearch'
import { LoadingSpinner } from './components/LoadingSpinner'
import { ErrorMessage } from './components/ErrorMessage'


// Fetched once, filtered client-side. selectedTeam toggles between table and detail view.
export function App() {
    const { standings, loading, error } = useStandings()
    const [conference, setConference] = useState<ConferenceFilter>('all')
    const [division, setDivision] = useState('all')
    const [search, setSearch] = useState('')

    const filtered = useMemo(() => {
        let result = standings
        if (search !== '') {
            result = result.filter((t) => t.teamName.default.toLowerCase().includes(search.toLowerCase()))
        }
        if (conference !== 'all') {
            result = result.filter((t) => t.conferenceName === conference)
        }
        if (division !== 'all') {
            result = result.filter((t) => t.divisionName === division)
        }
        return result
    }, [standings, conference, division, search])

    if (loading) return <LoadingSpinner />
    if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />

    return (
        <Routes>
            <Route path="/nhl-standings" element={
                <div>
                    <div className="mb-4">
                        <TeamSearch
                            search={search}
                            onSearchChange={setSearch} />
                        <div className="mt-3" />
                        <FilterButtons
                            conference={conference}
                            division={division}
                            onConferenceChange={setConference}
                            onDivisionChange={setDivision}
                        />
                    </div>
                    <StandingsTable standings={filtered} />
                </div>
            } />
            <Route path="/nhl-standings/team/:abbrev" element={<TeamDetail />} />
        </Routes>
    )
}
