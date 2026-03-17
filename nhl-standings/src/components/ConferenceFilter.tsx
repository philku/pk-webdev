import type { ConferenceFilter as FilterType } from '../types/standings'

// Division-Zuordnung: Welche Divisions gehören zu welcher Conference?
const DIVISIONS: Record<string, string[]> = {
    Eastern: ['Atlantic', 'Metropolitan'],
    Western: ['Central', 'Pacific'],
}

interface ConferenceFilterProps {
    conference: FilterType;
    division: string;
    onConferenceChange: (conference: FilterType) => void;
    onDivisionChange: (division: string) => void;
}

// Filter-Buttons für Conference und Division.
// Erste Zeile: Alle / Eastern / Western
// Zweite Zeile (nur wenn Conference gewählt): Divisions der gewählten Conference
export function ConferenceFilter({
    conference,
    division,
    onConferenceChange,
    onDivisionChange,
}: ConferenceFilterProps) {
    const conferences: FilterType[] = ['all', 'Eastern', 'Western']

    return (
        <div className="space-y-2">
            {/* Conference-Buttons */}
            <div className="flex flex-wrap gap-2">
                {conferences.map((c) => (
                    <button
                        key={c}
                        onClick={() => {
                            onConferenceChange(c)
                            onDivisionChange('all')
                        }}
                        className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                            conference === c
                                ? 'border-accent-500 bg-accent-50 text-accent-700'
                                : 'border-warm-300 text-warm-500 hover:border-warm-400'
                        }`}
                    >
                        {c === 'all' ? 'Alle' : c}
                    </button>
                ))}
            </div>

            {/* Division-Buttons — nur sichtbar wenn eine Conference gewählt ist */}
            {conference !== 'all' && (
                <div className="flex flex-wrap gap-2">
                    <button
                        onClick={() => onDivisionChange('all')}
                        className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                            division === 'all'
                                ? 'border-accent-500 bg-accent-50 text-accent-700'
                                : 'border-warm-300 text-warm-500 hover:border-warm-400'
                        }`}
                    >
                        Alle Divisions
                    </button>
                    {DIVISIONS[conference].map((d) => (
                        <button
                            key={d}
                            onClick={() => onDivisionChange(d)}
                            className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                                division === d
                                    ? 'border-accent-500 bg-accent-50 text-accent-700'
                                    : 'border-warm-300 text-warm-500 hover:border-warm-400'
                            }`}
                        >
                            {d}
                        </button>
                    ))}
                </div>
            )}
        </div>
    )
}
