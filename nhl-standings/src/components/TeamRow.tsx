import type { TeamStanding } from '../types/standings'

interface TeamRowProps {
    team: TeamStanding;
    rank: number;
    onSelect: (teamAbbrev: string) => void;
}

export function TeamRow({ team, rank, onSelect }: TeamRowProps) {
    const abbrev = team.teamAbbrev.default

    return (
        <tr
            onClick={() => onSelect(abbrev)}
            className="cursor-pointer transition-colors hover:bg-warm-50"
        >
            <td className="px-4 py-3 text-sm tabular-nums text-warm-400 text-center">
                {rank}
            </td>
            <td className="px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <img
                        src={team.teamLogo}
                        alt={team.teamName.default}
                        className="h-6 w-6 object-contain"
                        loading="lazy"
                    />
                    <span className="text-sm font-medium text-warm-900">
                        {team.teamName.default}
                    </span>
                </div>
            </td>
            <td className="px-4 py-3 text-sm tabular-nums text-warm-500 text-center">{team.gamesPlayed}</td>
            <td className="px-4 py-3 text-sm tabular-nums text-warm-500 text-center">{team.wins}</td>
            <td className="px-4 py-3 text-sm tabular-nums text-warm-500 text-center">{team.losses}</td>
            <td className="px-4 py-3 text-sm tabular-nums text-warm-500 text-center">{team.otLosses}</td>
            <td className="px-4 py-3 text-sm tabular-nums font-semibold text-warm-900 text-center">{team.points}</td>
            <td className="hidden sm:table-cell px-4 py-3 text-sm tabular-nums text-center">
                <span className={team.goalDifferential > 0 ? 'text-green-600' : team.goalDifferential < 0 ? 'text-red-500' : 'text-warm-400'}>
                    {team.goalDifferential > 0 ? '+' : ''}{team.goalDifferential}
                </span>
            </td>
            <td className="hidden sm:table-cell px-4 py-3 text-sm text-warm-500 text-center">
                {team.streakCode}{team.streakCount}
            </td>
        </tr>
    )
}
