import type { TeamStanding } from '../types/standings'
import { TeamRow } from './TeamRow'

interface StandingsTableProps {
    standings: TeamStanding[];
    onSelectTeam: (teamAbbrev: string) => void;
}

// Standings-Tabelle: Sortiert nach Liga-Platzierung (leagueSequence).
// Responsive: GD und Streak werden auf Mobile ausgeblendet.
export function StandingsTable({ standings, onSelectTeam }: StandingsTableProps) {
    // Sortierung nach leagueSequence (die API liefert das bereits korrekt)
    const sorted = [...standings].sort((a, b) => a.leagueSequence - b.leagueSequence)

    return (
        <div className="overflow-hidden rounded-xl border border-warm-200">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-warm-200 bg-warm-100">
                        <tr>
                            <th className="px-4 py-3 font-medium text-warm-600 text-center w-10">#</th>
                            <th className="px-4 py-3 font-medium text-warm-600">Team</th>
                            <th className="px-4 py-3 font-medium text-warm-600 text-center">GP</th>
                            <th className="px-4 py-3 font-medium text-warm-600 text-center">W</th>
                            <th className="px-4 py-3 font-medium text-warm-600 text-center">L</th>
                            <th className="px-4 py-3 font-medium text-warm-600 text-center">OTL</th>
                            <th className="px-4 py-3 font-medium text-warm-600 text-center">PTS</th>
                            <th className="hidden sm:table-cell px-4 py-3 font-medium text-warm-600 text-center">GD</th>
                            <th className="hidden sm:table-cell px-4 py-3 font-medium text-warm-600 text-center">Streak</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-warm-100">
                        {sorted.map((team, i) => (
                            <TeamRow
                                key={team.teamAbbrev.default}
                                team={team}
                                rank={i + 1}
                                onSelect={onSelectTeam}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    )
}
