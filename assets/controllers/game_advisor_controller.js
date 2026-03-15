import { Controller } from '@hotwired/stimulus';

/*
 * Stimulus Controller für den KI Game Berater.
 *
 * Steuert den gesamten User Flow:
 * 1. Plattform wählen (klickbare Cards)
 * 2. Games auswählen (Quick-Pick Cards + Suchfeld mit Autocomplete)
 * 3. KI-Empfehlung anfordern (SSE Stream)
 *
 * Targets:
 *   - platform:     Die Plattform-Buttons
 *   - gameSection:  Container für Game-Auswahl (initial hidden)
 *   - searchInput:  Das Autocomplete-Suchfeld
 *   - searchResults: Dropdown mit Suchergebnissen
 *   - selectedList: Anzeige der ausgewählten Games (Pills)
 *   - submitButton: Der "Was sollen wir zocken?" Button
 *   - resultSection: Container für das KI-Ergebnis (initial hidden)
 *   - resultText:   Div wo der gestreamte Text reinkommt
 *   - loading:      Lade-Animation während KI generiert
 *
 * Values:
 *   - searchUrl:    URL zum Suche-Endpoint (/ki-game-berater/api/search)
 *   - recommendUrl: URL zum Empfehlungs-Endpoint (/ki-game-berater/api/recommend)
 */
export default class extends Controller {
    static targets = [
        'platform', 'gameSection', 'searchInput', 'searchResults',
        'selectedHeading', 'selectedList', 'submitButton', 'resultSection', 'resultText', 'loading',
    ];

    static values = {
        searchUrl: String,
        recommendUrl: String,
    };

    connect() {
        // Zustand: welche Plattform gewählt, welche Games ausgewählt
        this.selectedPlatform = null;
        this.selectedGames = []; // Array von {id, name, cover} Objekten

        // Timer für Debounce der Suche
        this.searchTimer = null;
    }

    // --- Plattform wählen ---
    // Wird aufgerufen wenn eine Plattform-Card geklickt wird.
    // data-game-advisor-platform-param übergibt den Plattform-Namen.
    selectPlatform(event) {
        const button = event.currentTarget;
        this.selectedPlatform = button.dataset.gameAdvisorPlatformParam;

        // Alle Plattform-Buttons: aktiven highlighten, Rest zurücksetzen
        this.platformTargets.forEach(btn => {
            if (btn === button) {
                btn.classList.add('border-accent-500', 'bg-accent-50', 'text-accent-700');
                btn.classList.remove('border-warm-200', 'text-warm-500');
            } else {
                btn.classList.remove('border-accent-500', 'bg-accent-50', 'text-accent-700');
                btn.classList.add('border-warm-200', 'text-warm-500');
            }
        });

        // Game-Auswahl-Section einblenden
        this.gameSectionTarget.classList.remove('hidden');

        this.updateSubmitButton();
    }

    // --- Quick-Pick Game toggeln ---
    // Klick auf eine Game-Card: hinzufügen oder entfernen.
    toggleGame(event) {
        const card = event.currentTarget;
        const id = parseInt(card.dataset.gameAdvisorIdParam);
        const name = card.dataset.gameAdvisorNameParam;
        const cover = card.dataset.gameAdvisorCoverParam;

        const index = this.selectedGames.findIndex(g => g.id === id);

        // Overlay = der graue Schleier mit Häkchen über dem Cover
        const overlay = card.querySelector('[data-overlay]');

        if (index >= 0) {
            // Game entfernen
            this.selectedGames.splice(index, 1);
            card.classList.remove('border-accent-500');
            card.classList.add('border-warm-200');
            if (overlay) {
                overlay.classList.add('hidden');
                overlay.classList.remove('flex');
            }
        } else {
            // Game hinzufügen
            this.selectedGames.push({ id, name, cover });
            card.classList.remove('border-warm-200');
            card.classList.add('border-accent-500');
            if (overlay) {
                overlay.classList.remove('hidden');
                overlay.classList.add('flex');
            }
        }

        this.renderSelectedGames();
        this.updateSubmitButton();
    }

    // --- Autocomplete-Suche ---
    // Wird bei jeder Eingabe aufgerufen. Debounce: 300ms warten
    // nach dem letzten Tastendruck, dann erst den API-Call machen.
    // So sparen wir unnötige Requests während der User tippt.
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

    // --- Suchergebnis auswählen ---
    addSearchResult(event) {
        const item = event.currentTarget;
        const id = parseInt(item.dataset.gameAdvisorIdParam);
        const name = item.dataset.gameAdvisorNameParam;
        const cover = item.dataset.gameAdvisorCoverParam || '';

        // Duplikat-Check
        if (this.selectedGames.some(g => g.id === id)) {
            this.searchResultsTarget.classList.add('hidden');
            this.searchInputTarget.value = '';
            return;
        }

        this.selectedGames.push({ id, name, cover });

        // Suchfeld + Dropdown zurücksetzen
        this.searchInputTarget.value = '';
        this.searchResultsTarget.classList.add('hidden');

        this.renderSelectedGames();
        this.updateSubmitButton();
    }

    // --- Ausgewähltes Game entfernen (Klick auf X in der Pill) ---
    removeGame(event) {
        const id = parseInt(event.currentTarget.dataset.gameAdvisorIdParam);
        this.selectedGames = this.selectedGames.filter(g => g.id !== id);

        // Falls das Game auch in den Quick-Pick Cards war: Border + Overlay zurücksetzen
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

    // --- KI-Empfehlung anfordern ---
    // Schickt die ausgewählten Games + Plattform an den Server.
    // Liest die SSE-Response Chunk für Chunk und zeigt den Text live an.
    async submit() {
        if (!this.selectedPlatform || this.selectedGames.length === 0) return;

        // UI vorbereiten: Ergebnis-Section zeigen, Text leeren, Laden starten
        this.resultSectionTarget.classList.remove('hidden');
        this.resultTextTarget.textContent = '';
        this.loadingTarget.classList.remove('hidden');
        this.submitButtonTarget.disabled = true;
        this.submitButtonTarget.classList.add('opacity-50');

        // Zum Ergebnis scrollen — smooth, damit der User den Übergang sieht.
        this.resultSectionTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });

        try {
            // POST-Request mit Game-IDs und Plattform als JSON.
            // fetch() mit ReadableStream statt EventSource,
            // weil EventSource nur GET unterstützt und wir POST brauchen.
            const response = await fetch(this.recommendUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    gameIds: this.selectedGames.map(g => g.id),
                    platform: this.selectedPlatform,
                }),
            });

            // Lade-Animation ausblenden sobald die erste Antwort kommt
            this.loadingTarget.classList.add('hidden');

            // ReadableStream: Liest die Response Byte für Byte.
            // Jeder Chunk kann mehrere SSE-Zeilen enthalten,
            // oder eine Zeile kann über mehrere Chunks verteilt sein.
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // SSE-Zeilen verarbeiten (getrennt durch \n\n)
                const lines = buffer.split('\n');
                buffer = lines.pop(); // Letztes Fragment behalten (könnte unvollständig sein)

                for (const line of lines) {
                    if (!line.startsWith('data: ')) continue;

                    try {
                        const data = JSON.parse(line.substring(6));

                        if (data.error) {
                            this.resultTextTarget.textContent = data.error;
                            this.resetSubmitButton();
                            return;
                        }

                        if (data.text) {
                            this.resultTextTarget.textContent += data.text;
                        }

                        if (data.done) {
                            this.resetSubmitButton();
                            return;
                        }
                    } catch {
                        // Ungültiges JSON überspringen
                    }
                }
            }
        } catch {
            this.loadingTarget.classList.add('hidden');
            this.resultTextTarget.textContent =
                'Verbindung fehlgeschlagen. Stelle sicher dass Ollama läuft.';
        }

        this.resetSubmitButton();
    }

    // --- Hilfsfunktionen ---

    // Suchergebnisse als Dropdown rendern
    renderSearchResults(games) {
        if (games.length === 0) {
            this.searchResultsTarget.classList.add('hidden');
            return;
        }

        // Jedes Ergebnis als klickbare Zeile mit Cover-Thumbnail und Name
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

    // Ausgewählte Games als entfernbare Pills rendern
    renderSelectedGames() {
        if (this.selectedGames.length === 0) {
            this.selectedListTarget.innerHTML = '';
            // Überschrift ausblenden wenn keine Games gewählt
            this.selectedHeadingTarget.classList.add('hidden');
            return;
        }

        // Überschrift einblenden sobald mindestens 1 Game gewählt
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

    // Submit-Button: aktiv wenn Plattform + mindestens 1 Game gewählt
    updateSubmitButton() {
        const enabled = this.selectedPlatform && this.selectedGames.length > 0;
        this.submitButtonTarget.disabled = !enabled;
        this.submitButtonTarget.classList.toggle('opacity-50', !enabled);
    }

    resetSubmitButton() {
        this.submitButtonTarget.disabled = false;
        this.submitButtonTarget.classList.remove('opacity-50');
    }

    // HTML-Zeichen escapen um XSS zu verhindern.
    // Game-Namen kommen von einer externen API — wir dürfen sie
    // nicht ungefiltert ins DOM schreiben.
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
