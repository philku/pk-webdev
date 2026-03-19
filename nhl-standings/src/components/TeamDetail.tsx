import type { TeamStanding } from '../types/standings'
import { useRoster } from '../hooks/useRoster'
import { PlayerCard } from './PlayerCard'
import { LoadingSpinner } from './LoadingSpinner'

interface TeamDetailProps {
    team: TeamStanding;
    onBack: () => void;
}

export function TeamDetail({ team, onBack }: TeamDetailProps) {
    const abbrev = team.teamAbbrev.default
    const { roster, loading, error } = useRoster(abbrev)

    return (
        <div>
            {/* Back */}
            <button
                onClick={onBack}
                className="cursor-pointer inline-block rounded-lg border border-warm-300 px-5 py-2.5 text-sm font-medium text-warm-700 transition-colors hover:bg-warm-100"
            >
                &larr; Zurück zur Tabelle
            </button>

            {/* Header */}
            <div className="mt-6 flex items-center gap-4">
                <img
                    src={team.teamLogo}
                    alt={team.teamName.default}
                    className="h-16 w-16 object-contain"
                />
                <div>
                    <h2 className="font-heading text-2xl text-warm-900">
                        {team.teamName.default}
                    </h2>
                    <p className="mt-1 text-sm text-warm-500">
                        {team.wins}-{team.losses}-{team.otLosses} · {team.points} PTS
                        <span className="ml-2 text-warm-400">
                            {team.conferenceName} · {team.divisionName}
                        </span>
                    </p>
                </div>
            </div>

            {/* Roster */}
            <div className="mt-8">
                {loading && <LoadingSpinner />}

                {error && (
                    <p className="text-sm text-warm-500 py-8 text-center">
                        Fehler beim Laden des Rosters: {error}
                    </p>
                )}

                {roster && (
                    <div className="space-y-8">
                        {/* Forwards */}
                        <div>
                            <h3 className="font-heading text-lg text-warm-900">
                                Forwards
                                <span className="ml-2 text-sm font-normal text-warm-400">
                                    {roster.forwards.length}
                                </span>
                            </h3>
                            <div className="mt-3 grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                                {roster.forwards.map((player) => (
                                    <PlayerCard key={player.id} player={player} />
                                ))}
                            </div>
                        </div>

                        {/* Defensemen */}
                        <div>
                            <h3 className="font-heading text-lg text-warm-900">
                                Defense
                                <span className="ml-2 text-sm font-normal text-warm-400">
                                    {roster.defensemen.length}
                                </span>
                            </h3>
                            <div className="mt-3 grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                                {roster.defensemen.map((player) => (
                                    <PlayerCard key={player.id} player={player} />
                                ))}
                            </div>
                        </div>

                        {/* Goalies */}
                        <div>
                            <h3 className="font-heading text-lg text-warm-900">
                                Goalies
                                <span className="ml-2 text-sm font-normal text-warm-400">
                                    {roster.goalies.length}
                                </span>
                            </h3>
                            <div className="mt-3 grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                                {roster.goalies.map((player) => (
                                    <PlayerCard key={player.id} player={player} />
                                ))}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    )
}
