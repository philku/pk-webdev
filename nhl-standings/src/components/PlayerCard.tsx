import type { Player } from '../types/roster'

interface PlayerCardProps {
    player: Player;
}

export function PlayerCard({ player }: PlayerCardProps) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-warm-200 p-3">
            <img
                src={player.headshot}
                alt={`${player.firstName.default} ${player.lastName.default}`}
                className="h-12 w-12 rounded-full bg-warm-100 object-cover"
                loading="lazy"
            />
            <div className="min-w-0">
                <p className="text-sm font-medium text-warm-900 truncate">
                    {player.firstName.default} {player.lastName.default}
                </p>
                <p className="text-xs text-warm-400">
                    #{player.sweaterNumber} · {player.positionCode}
                </p>
            </div>
        </div>
    )
}
