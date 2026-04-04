import { useState } from 'react'
import type { TeamStanding, SortKey, SortDirection } from '../types/standings'
import { TeamRow } from './TeamRow'

interface StandingsTableProps {
    standings: TeamStanding[];
}

export function StandingsTable({ standings }: StandingsTableProps) {
    const [sortKey, setSortKey] = useState<SortKey>('points')
    const [sortDirection, setSortDirection] = useState<SortDirection>('desc')

    function handleSort(key: SortKey) {
        if (key === sortKey) {
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc')
        } else {
            setSortKey(key)
            setSortDirection('desc')
        }
    }

    const sorted = [...standings].sort((a, b) => {
        const diff = a[sortKey] - b[sortKey]
        return sortDirection === 'asc' ? diff : -diff
    })

    function sortIndicator(key: SortKey) {
        if (key !== sortKey) return null
        return <span className="ml-0.5 text-[10px] relative top-[-1px]">{sortDirection === 'asc' ? '▲' : '▼'}</span>
    }

    return (
        <div className="overflow-hidden rounded-xl border border-warm-200">
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-warm-200 bg-warm-100">
                    <tr>
                        <th className="px-4 py-3 font-medium text-warm-600 text-center w-10">#</th>
                        <th className="px-4 py-3 font-medium text-warm-600">Team</th>
                        <th
                            onClick={() => handleSort('gamesPlayed')}
                            className="px-4 py-3 font-medium text-warm-600 text-center cursor-pointer hover:text-warm-900"
                        >
                            GP{sortIndicator('gamesPlayed')}
                        </th>
                        <th
                            onClick={() => handleSort('wins')}
                            className="px-4 py-3 font-medium text-warm-600 text-center cursor-pointer hover:text-warm-900"
                        >
                            W{sortIndicator('wins')}
                        </th>
                        <th
                            onClick={() => handleSort('losses')}
                            className="px-4 py-3 font-medium text-warm-600 text-center cursor-pointer hover:text-warm-900"
                        >
                            L{sortIndicator('losses')}
                        </th>
                        <th className="px-4 py-3 font-medium text-warm-600 text-center">OTL</th>
                        <th
                            onClick={() => handleSort('points')}
                            className="px-4 py-3 font-medium text-warm-600 text-center cursor-pointer hover:text-warm-900"
                        >
                            PTS{sortIndicator('points')}
                        </th>
                        <th
                            onClick={() => handleSort('goalDifferential')}
                            className="hidden sm:table-cell px-4 py-3 font-medium text-warm-600 text-center cursor-pointer hover:text-warm-900"
                        >
                            GD{sortIndicator('goalDifferential')}
                        </th>
                        <th className="hidden sm:table-cell px-4 py-3 font-medium text-warm-600 text-center">Streak</th>
                    </tr>
                    </thead>
                    <tbody className="divide-y divide-warm-100">
                    {sorted.map((team, i) => (
                        <TeamRow
                            key={team.teamAbbrev.default}
                            team={team}
                            rank={i + 1}
                        />
                    ))}
                    </tbody>
                </table>
            </div>
        </div>
    )
}
