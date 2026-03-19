import { Controller } from '@hotwired/stimulus';

// Fetches song play counts and renders horizontal bar chart.
export default class extends Controller {
    static targets = [
        'playedContainer',
    ];

    static values = {
        url: String,
    };

    async connect() {
        try {
            const response = await fetch(this.urlValue);
            this.statsData = await response.json();
            this.renderPlayedBars();
        } catch (error) {
            console.error('Failed to load stats:', error);
        }
    }

    renderPlayedBars() {
        const { topPlayed } = this.statsData;

        if (!topPlayed?.length) {
            this.playedContainerTarget.innerHTML =
                '<p class="text-sm text-warm-400 py-8 text-center">' +
                'Noch keine Live-Daten verfügbar. Die Konzertkarte muss mindestens einmal vollständig geladen werden.' +
                '</p>';
            return;
        }

        const maxCount = topPlayed[0].count;

        const bars = topPlayed.map((song, i) => {
            const percent = (song.count / maxCount) * 100;
            const rank = i + 1;

            const isTop3 = rank <= 3;
            const barOpacity = isTop3 ? 0.2 : 0.1;
            const borderOpacity = isTop3 ? 0.5 : 0.25;
            const rankBadge = isTop3
                ? `<span class="shrink-0 w-6 h-6 rounded-full bg-accent-600 text-white text-[11px] font-semibold flex items-center justify-center">${rank}</span>`
                : `<span class="shrink-0 w-6 text-center text-xs text-warm-400">${rank}</span>`;

            const displayName = song.name
                .split(' ')
                .map(w => w.charAt(0).toUpperCase() + w.slice(1))
                .join(' ');

            return `
                <div class="group relative rounded-lg overflow-hidden transition-colors hover:bg-accent-600/[0.04]">
                    <div class="absolute inset-y-0 left-0 rounded-lg transition-all duration-500"
                         style="width: ${percent}%;
                                background: rgba(13, 148, 136, ${barOpacity});">
                    </div>
                    <div class="relative flex items-center gap-3 px-3 py-2.5 sm:py-2">
                        ${rankBadge}
                        <span class="flex-1 min-w-0 text-sm sm:text-[13px] font-medium text-warm-800 truncate">${displayName}</span>
                        <span class="shrink-0 text-sm sm:text-[13px] font-semibold tabular-nums text-accent-700">${song.count.toLocaleString('de-DE')}</span>
                    </div>
                </div>
            `;
        }).join('');

        this.playedContainerTarget.innerHTML = `
            <div class="space-y-1">
                ${bars}
            </div>
        `;
    }
}
