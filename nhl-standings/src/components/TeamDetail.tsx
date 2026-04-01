import { useParams, useNavigate } from 'react-router-dom'
import { useStandings } from '../hooks/useStandings'
import { useRoster } from '../hooks/useRoster'
import { PlayerCard } from './PlayerCard'
import { LoadingSpinner } from './LoadingSpinner'

export function TeamDetail() {
    const { abbrev } = useParams()
    const { roster, loading: rosterLoading, error: rosterError } = useRoster(abbrev!)
    const navigate = useNavigate()
    const { standings } = useStandings()
    const team = standings.find((t) => t.teamAbbrev.default === abbrev)

    return (
        <div>
            {/* Back */}
            <button
                onClick={() => navigate('/nhl-standings')}
                className="cursor-pointer inline-block rounded-lg border border-warm-300 px-5 py-2.5 text-sm font-medium text-warm-700 transition-colors hover:bg-warm-100"
            >
                &larr; Zurück zur Tabelle
            </button>

            {/* Header */}
            {team && (
                <div className="mt-6 flex items-center gap-4">
                    <img alt={team.teamName.default}
                         className="h-16 w-16 object-contain"
                         src={team.teamLogo} />
                    <div>
                        <h2 className="font-heading text-2xl text-warm-900">{team.teamName.default}</h2>
                        <p className="mt-1 text-sm text-warm-500">{team.wins}-{team.losses}-{team.otLosses} · {team.points} PTS
                            <span className="ml-2 text-warm-400">{team.conferenceName} · {team.divisionName}</span>
                        </p>
                    </div>
                </div>
            )}


            {/* Roster */}
            <div className="mt-8">
                {rosterLoading && <LoadingSpinner/>}

                {rosterError && (
                    <p className="text-sm text-warm-500 py-8 text-center">
                        Fehler beim Laden des Rosters: {rosterError}
                    </p>
                )}

                {roster && (
                    <div className="space-y-8">
                        {[
                            {title: 'Forwards', players: roster.forwards},
                            {title: 'Defense', players: roster.defensemen},
                            {title: 'Goalies', players: roster.goalies}
                        ].map((group) => (
                            <div key={group.title}>
                                <h3 className="font-heading text-lg text-warm-900">
                                    {group.title}
                                    <span className="ml-2 text-sm font-normal text-warm-400">
                                        {group.players.length}
                                    </span>
                                </h3>
                                <div className="mt-3 grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                                    {group.players.map((player) => (
                                        <PlayerCard key={player.id} player={player}/>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    )
}
