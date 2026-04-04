interface TeamSearchProps {
    search: string;
    onSearchChange: (search: string) => void;
}

export function TeamSearch({
    search,
    onSearchChange,
}: TeamSearchProps) {
    return (
        <input
            type="text"
            placeholder="Team suchen..."
            className="w-full rounded-lg border border-warm-200 bg-white px-4 py-2 text-sm text-warm-900 placeholder-warm-400 outline-none focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
            value={search}
            onChange={(e) => onSearchChange(e.target.value)} />
    )
}
