import { Controller } from '@hotwired/stimulus';

// Multi-step flow: platform → player count → game selection → LLM recommendation (SSE).
export default class extends Controller {
    static targets = [
        'platform', 'playerSection', 'playerCount', 'gameSection', 'searchInput', 'searchResults',
        'selectedHeading', 'selectedList', 'submitButton', 'resultSection', 'resultText', 'loading',
    ];

    static values = {
        searchUrl: String,
        recommendUrl: String,
    };

    connect() {
        this.selectedPlatform = null;
        this.selectedPlayerCount = null;
        this.selectedGames = [];
        this.searchTimer = null;
    }

    selectPlatform(event) {
        const button = event.currentTarget;
        this.selectedPlatform = button.dataset.gameAdvisorPlatformParam;

        this.platformTargets.forEach(btn => {
            if (btn === button) {
                btn.classList.add('border-accent-500', 'bg-accent-50', 'text-accent-700');
                btn.classList.remove('border-warm-200', 'text-warm-500');
            } else {
                btn.classList.remove('border-accent-500', 'bg-accent-50', 'text-accent-700');
                btn.classList.add('border-warm-200', 'text-warm-500');
            }
        });

        this.playerSectionTarget.classList.remove('hidden');

        this.updateSubmitButton();
    }

    selectPlayerCount(event) {
        const button = event.currentTarget;
        this.selectedPlayerCount = parseInt(button.dataset.gameAdvisorCountParam);

        this.playerCountTargets.forEach(btn => {
            if (btn === button) {
                btn.classList.add('border-accent-500', 'bg-accent-50', 'text-accent-700');
                btn.classList.remove('border-warm-200', 'text-warm-500');
            } else {
                btn.classList.remove('border-accent-500', 'bg-accent-50', 'text-accent-700');
                btn.classList.add('border-warm-200', 'text-warm-500');
            }
        });

        this.gameSectionTarget.classList.remove('hidden');

        this.updateSubmitButton();
    }

    toggleGame(event) {
        const card = event.currentTarget;
        const id = parseInt(card.dataset.gameAdvisorIdParam);
        const name = card.dataset.gameAdvisorNameParam;
        const cover = card.dataset.gameAdvisorCoverParam;

        const index = this.selectedGames.findIndex(g => g.id === id);

        const overlay = card.querySelector('[data-overlay]');

        if (index >= 0) {
            this.selectedGames.splice(index, 1);
            card.classList.remove('border-accent-500', 'hover:border-accent-600');
            card.classList.add('border-warm-200', 'hover:border-warm-400');
            if (overlay) {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }
        } else {
            this.selectedGames.push({ id, name, cover });
            card.classList.remove('border-warm-200', 'hover:border-warm-400');
            card.classList.add('border-accent-500', 'hover:border-accent-600');
            if (overlay) {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }
        }

        this.renderSelectedGames();
        this.updateSubmitButton();
    }

    // 300ms debounce to avoid requests on every keystroke.
    search() {
        clearTimeout(this.searchTimer);

        const query = this.searchInputTarget.value.trim();

        if (query.length < 2) {
            this.searchResultsTarget.classList.add('hidden');
            return;
        }

        this.searchTimer = setTimeout(async () => {
            try {
                const response = await fetch(
                    `${this.searchUrlValue}?q=${encodeURIComponent(query)}`
                );
                const games = await response.json();

                this.renderSearchResults(games);
            } catch {
                this.searchResultsTarget.classList.add('hidden');
            }
        }, 300);
    }

    addSearchResult(event) {
        const item = event.currentTarget;
        const id = parseInt(item.dataset.gameAdvisorIdParam);
        const name = item.dataset.gameAdvisorNameParam;
        const cover = item.dataset.gameAdvisorCoverParam || '';

        if (this.selectedGames.some(g => g.id === id)) {
            this.searchResultsTarget.classList.add('hidden');
            this.searchInputTarget.value = '';
            return;
        }

        this.selectedGames.push({ id, name, cover });

        this.searchInputTarget.value = '';
        this.searchResultsTarget.classList.add('hidden');

        this.renderSelectedGames();
        this.updateSubmitButton();
    }

    removeGame(event) {
        const id = parseInt(event.currentTarget.dataset.gameAdvisorIdParam);
        this.selectedGames = this.selectedGames.filter(g => g.id !== id);

        // Reset quick-pick card styling if this game had one.
        document.querySelectorAll(`[data-game-advisor-id-param="${id}"]`).forEach(card => {
            card.classList.remove('border-accent-500');
            card.classList.add('border-warm-200');
            const overlay = card.querySelector('[data-overlay]');
            if (overlay) {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }
        });

        this.renderSelectedGames();
        this.updateSubmitButton();
    }

    // Streams LLM response via SSE using ReadableStream (POST, not EventSource).
    async submit() {
        if (!this.selectedPlatform || !this.selectedPlayerCount || this.selectedGames.length === 0) return;

        this.resultSectionTarget.classList.remove('hidden');
        this.resultTextTarget.innerHTML = '';
        this.streamedText = '';
        this.renderedLineCount = 0;
        this.loadingTarget.classList.remove('hidden');
        this.submitButtonTarget.disabled = true;
        this.submitButtonTarget.classList.add('opacity-50');

        this.resultSectionTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });

        try {
            const response = await fetch(this.recommendUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    gameIds: this.selectedGames.map(g => g.id),
                    platform: this.selectedPlatform,
                    playerCount: this.selectedPlayerCount,
                }),
            });

            this.loadingTarget.classList.add('hidden');

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                const lines = buffer.split('\n');
                buffer = lines.pop(); // Keep incomplete fragment

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;

                    try {
                        const data = JSON.parse(line.substring(6));

                        if (data.error) {
                            this.resultTextTarget.innerHTML = `<p class="text-red-600">${this.escapeHtml(data.error)}</p>`;
                            this.resetSubmitButton();
                            return;
                        }

                        if (data.text) {
                            this.streamedText += data.text;
                            this.renderStreamedText();
                        }

                        if (data.done) {
                            this.resetSubmitButton();
                            return;
                        }
                    } catch {
                        // Skip malformed JSON chunks.
                    }
                }
            }
        } catch {
            this.loadingTarget.classList.add('hidden');
            this.resultTextTarget.innerHTML =
                '<p class="text-red-600">Verbindung fehlgeschlagen. Bitte versuche es später erneut.</p>';
        }

        this.resetSubmitButton();
    }

    // Incrementally appends completed lines as permanent DOM elements.
    // The last (incomplete) line uses a temporary element that gets replaced.
    renderStreamedText() {
        const lines = this.streamedText.split('\n');
        const incompleteLine = lines.pop();

        const streaming = this.resultTextTarget.querySelector('[data-streaming]');
        if (streaming) streaming.remove();

        while (this.renderedLineCount < lines.length) {
            const trimmed = lines[this.renderedLineCount].trim();
            this.renderedLineCount++;

            if (trimmed === '') continue;

            if (trimmed.startsWith('### ') || trimmed.startsWith('## ')) {
                const title = this.escapeHtml(trimmed.replace(/^#{2,3}\s*/, ''));
                const div = document.createElement('div');
                div.className = 'mt-6 mb-2 border-l-4 border-accent-500 pl-4';
                div.innerHTML = `<h3 class="font-heading text-lg text-warm-900">${title}</h3>`;
                this.resultTextTarget.appendChild(div);
            } else {
                const p = document.createElement('p');
                p.className = 'mt-1 text-warm-600 leading-relaxed';
                p.innerHTML = this.formatInlineMarkdown(trimmed);
                this.resultTextTarget.appendChild(p);
            }
        }

        const trimmedIncomplete = incompleteLine.trim();
        if (trimmedIncomplete) {
            const el = document.createElement('p');
            el.setAttribute('data-streaming', '');
            el.className = 'mt-1 text-warm-600 leading-relaxed';
            el.innerHTML = this.formatInlineMarkdown(trimmedIncomplete);
            this.resultTextTarget.appendChild(el);
        }
    }

    formatInlineMarkdown(text) {
        const escaped = this.escapeHtml(text);
        return escaped.replace(/\*{1,2}([^*]+)\*{1,2}/g,
            '<span class="font-medium text-warm-800">$1</span>');
    }

    renderSearchResults(games) {
        if (games.length === 0) {
            this.searchResultsTarget.classList.add('hidden');
            return;
        }

        this.searchResultsTarget.innerHTML = games.map(game => `
            <button type="button"
                class="cursor-pointer flex w-full items-center gap-3 px-4 py-2 text-left text-sm hover:bg-warm-100 transition-colors"
                data-action="click->game-advisor#addSearchResult"
                data-game-advisor-id-param="${game.id}"
                data-game-advisor-name-param="${this.escapeHtml(game.name)}"
                data-game-advisor-cover-param="${game.cover || ''}">
                ${game.cover
                    ? `<img src="${game.cover}" alt="" class="h-10 w-8 rounded object-cover">`
                    : `<div class="h-10 w-8 rounded bg-warm-200"></div>`
                }
                <div>
                    <p class="font-medium text-warm-900">${this.escapeHtml(game.name)}</p>
                    <p class="text-xs text-warm-400">${(game.genres || []).join(', ')}</p>
                </div>
            </button>
        `).join('');

        this.searchResultsTarget.classList.remove('hidden');
    }

    renderSelectedGames() {
        if (this.selectedGames.length === 0) {
            this.selectedListTarget.innerHTML = '';
            this.selectedHeadingTarget.classList.add('hidden');
            return;
        }

        this.selectedHeadingTarget.classList.remove('hidden');

        this.selectedListTarget.innerHTML = this.selectedGames.map(game => `
            <span class="inline-flex items-center gap-2 rounded-full border border-accent-200 bg-accent-50 pl-4 pr-2 py-1.5 text-sm text-accent-700">
                ${this.escapeHtml(game.name)}
                <button type="button"
                    class="flex h-7 w-7 cursor-pointer items-center justify-center rounded-full bg-accent-200 text-accent-700 text-base leading-none font-bold hover:bg-accent-300 transition-colors"
                    data-action="click->game-advisor#removeGame"
                    data-game-advisor-id-param="${game.id}">
                    ✕
                </button>
            </span>
        `).join('');
    }

    updateSubmitButton() {
        const enabled = this.selectedPlatform && this.selectedPlayerCount && this.selectedGames.length > 0;
        this.submitButtonTarget.disabled = !enabled;
        this.submitButtonTarget.classList.toggle('opacity-50', !enabled);
    }

    resetSubmitButton() {
        this.submitButtonTarget.disabled = false;
        this.submitButtonTarget.classList.remove('opacity-50');
    }

    // XSS protection — game names come from external API.
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
