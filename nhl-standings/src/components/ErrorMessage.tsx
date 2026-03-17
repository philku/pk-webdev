interface ErrorMessageProps {
    message: string;
    onRetry: () => void;
}

// Fehler-Anzeige mit Retry-Button.
export function ErrorMessage({ message, onRetry }: ErrorMessageProps) {
    return (
        <div className="rounded-xl border border-warm-200 bg-warm-50 px-6 py-12 text-center">
            <p className="text-sm text-warm-500">
                Fehler beim Laden: {message}
            </p>
            <button
                onClick={onRetry}
                className="mt-4 rounded-lg border border-warm-300 px-4 py-2 text-sm font-medium text-warm-700 transition-colors hover:bg-warm-100"
            >
                Erneut versuchen
            </button>
        </div>
    )
}
