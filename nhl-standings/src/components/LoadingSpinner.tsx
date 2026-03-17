// Lade-Animation — gleiches Pattern wie auf der Metallica-Seite.
export function LoadingSpinner() {
    return (
        <div className="flex items-center justify-center py-20">
            <div className="text-center">
                <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-warm-300 border-t-accent-600" />
                <p className="mt-4 text-sm text-warm-500">Lade Daten...</p>
            </div>
        </div>
    )
}
