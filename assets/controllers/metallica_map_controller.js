import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

// --- Leaflet Marker-Icons Fix ---
// Leaflet versucht normalerweise, den Pfad zu den Marker-Bildern (Pin-Icons)
// automatisch aus dem CSS zu erkennen. Das funktioniert nur wenn Leaflet
// per klassischem <script>-Tag geladen wird.
// Bei ES-Modul-Import (wie hier über ImportMap) schlägt die Auto-Erkennung fehl
// → die Icons werden nicht gefunden → "missing picture" Symbol.
// Fix: Die Icon-URLs manuell auf die CDN-Pfade setzen.
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-icon.png',
    iconRetinaUrl: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-icon-2x.png',
    shadowUrl: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-shadow.png',
});

/*
 * Stimulus Controller für die Metallica-Konzert-Weltkarte.
 *
 * Warum ein Stimulus Controller statt Inline-JavaScript?
 * - connect() wird automatisch aufgerufen wenn das Element im DOM erscheint
 * - disconnect() räumt automatisch auf wenn das Element verschwindet
 * - Kein manuelles turbo:load / turbo:before-cache nötig — Stimulus
 *   kümmert sich um den kompletten Lifecycle bei Turbo-Navigation
 * - Saubere Trennung: Twig = HTML-Struktur, Controller = Logik
 *
 * Targets (Elemente im Template, auf die der Controller zugreift):
 *   - mapContainer: Das <div> in dem die Leaflet-Karte gerendert wird
 *   - loading:      Der Lade-Indikator (Spinner), wird ausgeblendet wenn Daten da sind
 *   - concertList:  Container für die "Letzte Konzerte"-Liste
 *   - statTotal, statCountries, statCities, statYears: Die 4 Statistik-Kacheln
 *   - bottomSheet, sheetBackdrop, sheetTitle, sheetSubtitle, sheetList: Mobile Bottom Sheet
 *
 * Values (Daten die per data-Attribut vom Template übergeben werden):
 *   - url: Die URL zum JSON-Endpoint (/metallica/api/concerts)
 */
export default class extends Controller {
    static targets = [
        'mapContainer',
        'loading',
        'concertList',
        'statTotal',
        'statCountries',
        'statCities',
        'statYears',
        // Progress Bar Targets — zeigen den Ladefortschritt beim progressiven Laden
        'progress',
        'progressBar',
        'progressText',
        // Mobile Bottom Sheet Targets
        'bottomSheet',
        'sheetBackdrop',
        'sheetTitle',
        'sheetSubtitle',
        'sheetList',
    ];

    static values = {
        url: String,
    };

    connect() {
        this.map = L.map(this.mapContainerTarget).setView([30, 0], 2);

        // Tile Layer = die Kartenkacheln von OpenStreetMap.
        // Leaflet ist nur das JS-Framework — die Kartenbilder kommen von OSM.
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18,
        }).addTo(this.map);

        // Layer für die Konzert-Marker
        this.markers = L.layerGroup().addTo(this.map);

        // Konzertdaten laden
        this.loadConcerts();
    }

    disconnect() {
        // Laufende fetch-Requests abbrechen wenn der User per Turbo
        // wegnavigiert. Ohne das würde der Loading-Loop weiterlaufen
        // und Fehler werfen weil die DOM-Elemente nicht mehr existieren.
        if (this.abortController) {
            this.abortController.abort();
        }
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    // --- Mobile-Erkennung ---
    // Prüft ob der Viewport schmaler als 640px ist (Tailwind's sm-Breakpoint).
    // Auf Mobile zeigen wir ein Bottom Sheet statt des kleinen Leaflet-Popups,
    // weil Popups auf Touch-Screens schlecht bedienbar sind (zu klein, kein Scroll).
    get isMobile() {
        return window.innerWidth < 640;
    }

    // --- Konzertdaten laden: Full-Cache oder progressiv ---
    // Ablauf:
    //   1. Erster Request OHNE ?page → Backend prüft ob Full-Cache existiert
    //   2a. complete: true  → Alle 2000+ Konzerte auf einen Schlag (aus Full-Cache)
    //   2b. complete: false → Nur Seite 1 kam zurück, Rest wird progressiv nachgeladen
    //   3. Wenn alle Seiten geladen → Backend baut automatisch den Full-Cache
    //   4. Nächster Besuch → Schritt 2a (alles sofort da)
    async loadConcerts() {
        this.abortController = new AbortController();
        this.allConcerts = [];

        try {
            // --- Schritt 1: Initialer Request ohne ?page ---
            // Das Backend entscheidet: Full-Cache vorhanden → alles auf einmal,
            // sonst → erste Seite + complete: false
            const initialData = await this.fetchUrl(this.urlValue);

            // Spinner weg, Karte da
            this.loadingTarget.classList.add('hidden');
            this.mapContainerTarget.classList.remove('hidden');
            this.map.invalidateSize();

            // --- Schritt 2a: Full-Cache → sofort fertig ---
            if (initialData.complete) {
                this.allConcerts = initialData.concerts;
                this.refreshMap();
                return; // Fertig! Kein progressives Laden nötig.
            }

            // --- Schritt 2b: Kein Full-Cache → progressive Loading ---
            // Erste Seite (20 Konzerte) sofort anzeigen
            this.allConcerts = [...initialData.concerts];
            this.refreshMap();

            const totalPages = initialData.totalPages;

            if (totalPages > 1) {
                this.showProgress(1, totalPages, initialData.total);

                // Restliche Seiten sequenziell nachladen.
                // Sequenziell (nicht parallel) wegen setlist.fm Rate Limit (~2 Req/Sek).
                for (let page = 2; page <= totalPages; page++) {
                    const data = await this.fetchPage(page);

                    this.allConcerts.push(...data.concerts);
                    this.refreshMap();
                    this.showProgress(page, totalPages, initialData.total);
                }

                // Alles geladen — Progress Bar ausblenden.
                // Der Full-Cache wurde serverseitig automatisch gebaut
                // als die letzte Seite angefragt wurde.
                this.hideProgress();
            }
        } catch (error) {
            if (error.name === 'AbortError') return;

            this.loadingTarget.innerHTML = `
                <p class="text-sm text-red-600">Fehler beim Laden der Konzertdaten.</p>
            `;
            console.error('Fehler:', error);
        }
    }

    // --- Beliebige URL fetchen (mit AbortController-Support) ---
    // Wird für den initialen Request ohne ?page benutzt.
    async fetchUrl(url) {
        const response = await fetch(url, {
            signal: this.abortController.signal,
        });
        return response.json();
    }

    // --- Eine bestimmte Seite Konzertdaten vom Server holen ---
    // Gibt ein Objekt zurück: { concerts: [...], page: 1, totalPages: 109, total: 2177 }
    async fetchPage(page) {
        return this.fetchUrl(`${this.urlValue}?page=${page}`);
    }

    // --- Karte komplett neu aufbauen mit allen bisher geladenen Konzerten ---
    // Wird nach jeder neuen Seite aufgerufen. Leaflet's clearLayers() +
    // neu addMarkers() ist performanter als einzelne Marker zu tracken,
    // weil die Gruppierung nach Ort (gleiche lat/lng) sich ändert.
    refreshMap() {
        this.markers.clearLayers();
        this.addMarkers(this.allConcerts);
        this.updateStats(this.allConcerts);
        this.renderConcertList(this.allConcerts);
    }

    // --- Fortschrittsbalken anzeigen ---
    showProgress(currentPage, totalPages, total) {
        this.progressTarget.classList.remove('hidden');

        // Prozent berechnen für die Breite des Balkens
        const percent = Math.round((currentPage / totalPages) * 100);
        this.progressBarTarget.style.width = `${percent}%`;

        // Text: "985 von ~2.177 Konzerten geladen"
        this.progressTextTarget.textContent =
            `${this.allConcerts.length.toLocaleString('de-DE')} von ~${total.toLocaleString('de-DE')} Konzerten`;
    }

    // --- Fortschrittsbalken ausblenden ---
    hideProgress() {
        this.progressTarget.classList.add('hidden');
    }

    // --- Statistik-Kacheln befüllen ---
    updateStats(concerts) {
        const countries = new Set(concerts.map(c => c.country));
        const cities = new Set(concerts.map(c => c.city));

        const years = concerts.map(c => {
            const parts = c.date.split('-');
            return parseInt(parts[2]);
        }).filter(y => y > 0);

        const minYear = Math.min(...years);
        const maxYear = Math.max(...years);

        this.statTotalTarget.textContent = concerts.length.toLocaleString('de-DE');
        this.statCountriesTarget.textContent = countries.size;
        this.statCitiesTarget.textContent = cities.size;
        this.statYearsTarget.textContent = (maxYear - minYear + 1);
    }

    // --- Konzerte nach Datum sortieren (neueste zuerst) ---
    // Wird an mehreren Stellen gebraucht, daher eigene Methode.
    sortByDate(concerts) {
        return [...concerts].sort((a, b) => {
            const [dA, mA, yA] = a.date.split('-').map(Number);
            const [dB, mB, yB] = b.date.split('-').map(Number);
            return new Date(yB, mB - 1, dB) - new Date(yA, mA - 1, dA);
        });
    }

    // --- Marker auf die Karte setzen ---
    addMarkers(concerts) {
        // Konzerte nach Koordinaten gruppieren.
        // Metallica hat in vielen Venues mehrfach gespielt.
        // Ohne Gruppierung stacken sich Marker übereinander
        // und man kann nur das oberste Konzert anklicken.
        const groups = {};
        concerts.forEach(concert => {
            const key = concert.lat + ',' + concert.lng;
            if (!groups[key]) groups[key] = [];
            groups[key].push(concert);
        });

        // Pro Ort einen Marker erstellen
        Object.entries(groups).forEach(([key, groupConcerts]) => {
            const [lat, lng] = key.split(',').map(Number);
            const first = groupConcerts[0];

            const marker = L.marker([lat, lng]);

            // Auf Mobile: Klick öffnet Bottom Sheet statt Popup.
            // Auf Desktop: normales Leaflet-Popup mit gestyltem Inhalt.
            marker.on('click', () => {
                if (this.isMobile) {
                    this.openSheet(first, groupConcerts);
                } else {
                    // Popup manuell öffnen (statt bindPopup),
                    // damit wir den Click-Handler kontrollieren können.
                    marker.unbindPopup();
                    marker.bindPopup(this.buildPopupHtml(first, groupConcerts), {
                        maxWidth: 300,
                        className: 'metallica-popup',
                    }).openPopup();
                }
            });

            this.markers.addLayer(marker);
        });
    }

    // --- Desktop-Popup HTML bauen ---
    buildPopupHtml(first, groupConcerts) {
        if (groupConcerts.length === 1) {
            // Einzelnes Konzert
            return `
                <div class="metallica-popup-content">
                    <strong class="metallica-popup-venue">${first.venue}</strong>
                    <span class="metallica-popup-location">${first.city}, ${first.country}</span>
                    <span class="metallica-popup-date">${first.date}</span>
                    ${first.tour ? '<span class="metallica-popup-tour">' + first.tour + '</span>' : ''}
                    ${first.songCount > 0 ? '<span class="metallica-popup-songs">' + first.songCount + ' Songs</span>' : ''}
                    <a href="/metallica/setlist/${first.id}" class="metallica-popup-link">Setlist ansehen &rarr;</a>
                </div>
            `;
        }

        // Mehrere Konzerte am gleichen Ort
        const sorted = this.sortByDate(groupConcerts);
        const listItems = sorted.map(c => `
            <a href="/metallica/setlist/${c.id}" class="metallica-popup-item">
                <span class="metallica-popup-item-date">${c.date}</span>
                ${c.tour ? '<span class="metallica-popup-item-tour">' + c.tour + '</span>' : ''}
                ${c.songCount > 0 ? '<span class="metallica-popup-item-songs">' + c.songCount + '</span>' : ''}
            </a>
        `).join('');

        return `
            <div class="metallica-popup-content">
                <strong class="metallica-popup-venue">${first.venue}</strong>
                <span class="metallica-popup-location">${first.city}, ${first.country}</span>
                <span class="metallica-popup-count">${groupConcerts.length} Konzerte</span>
                <div class="metallica-popup-list">
                    ${listItems}
                </div>
            </div>
        `;
    }

    // --- Mobile Bottom Sheet öffnen ---
    // Zeigt das Bottom Sheet mit Venue-Info und Konzertliste.
    // Das Sheet slided von unten rein (CSS transform + transition).
    openSheet(venue, groupConcerts) {
        // Leaflet-Popup schließen falls eins offen ist
        this.map.closePopup();

        // Header befüllen
        this.sheetTitleTarget.textContent = venue.venue;
        this.sheetSubtitleTarget.textContent = `${venue.city}, ${venue.country}`;

        // Konzertliste befüllen — große Touch-Targets (min 48px Höhe)
        const sorted = this.sortByDate(groupConcerts);
        this.sheetListTarget.innerHTML = sorted.map(c => `
            <a href="/metallica/setlist/${c.id}"
               class="flex items-center justify-between py-3 px-3 -mx-3 rounded-lg active:bg-warm-100 transition-colors">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-warm-900">${c.date}</p>
                    ${c.tour ? '<p class="text-xs text-warm-500 truncate">' + c.tour + '</p>' : ''}
                </div>
                ${c.songCount > 0
                    ? '<span class="ml-3 shrink-0 text-xs text-warm-400">' + c.songCount + ' Songs</span>'
                    : ''}
            </a>
        `).join('');

        // Sheet reinschieben (CSS transition: translate-y-full → translate-y-0)
        this.bottomSheetTarget.classList.remove('translate-y-full');
        this.bottomSheetTarget.classList.add('translate-y-0');

        // Backdrop einblenden
        this.sheetBackdropTarget.classList.remove('opacity-0', 'pointer-events-none');
        this.sheetBackdropTarget.classList.add('opacity-100');

        // Scroll auf der Seite sperren während das Sheet offen ist
        document.body.style.overflow = 'hidden';
    }

    // --- Mobile Bottom Sheet schließen ---
    // Wird aufgerufen bei:
    //   - Tap auf den Backdrop (data-action="click->metallica-map#closeSheet")
    //   - Swipe nach unten auf dem Drag-Handle (onSwipeEnd)
    closeSheet() {
        this.bottomSheetTarget.classList.add('translate-y-full');
        this.bottomSheetTarget.classList.remove('translate-y-0');
        // Inline-Transform vom Swipen zurücksetzen
        this.bottomSheetTarget.style.transform = '';

        this.sheetBackdropTarget.classList.add('opacity-0', 'pointer-events-none');
        this.sheetBackdropTarget.classList.remove('opacity-100');

        // Scroll wieder freigeben
        document.body.style.overflow = '';
    }

    // --- Swipe-to-close: Touch-Events ---
    // Drei Phasen: Start (Finger drauf), Move (Finger zieht), End (Finger los).
    // Wenn der User das Sheet weit genug nach unten zieht (>80px),
    // schließen wir es. Sonst snappt es zurück.

    // Phase 1: Startposition merken
    onSwipeStart(event) {
        this.swipeStartY = event.touches[0].clientY;
        // Transition kurz deaktivieren, damit das Sheet dem Finger
        // ohne Verzögerung folgt (sonst "rutscht" es hinterher).
        this.bottomSheetTarget.style.transition = 'none';
    }

    // Phase 2: Sheet folgt dem Finger nach unten
    onSwipeMove(event) {
        if (this.swipeStartY === undefined) return;

        const currentY = event.touches[0].clientY;
        // deltaY = wie weit der Finger nach unten gezogen hat
        const deltaY = currentY - this.swipeStartY;

        // Nur nach unten ziehen erlauben (deltaY > 0),
        // nicht nach oben über die Ausgangsposition hinaus.
        if (deltaY > 0) {
            this.bottomSheetTarget.style.transform = `translateY(${deltaY}px)`;
            // Backdrop wird proportional ausgeblendet — je weiter man zieht,
            // desto transparenter wird er.
            const opacity = Math.max(0, 1 - deltaY / 300);
            this.sheetBackdropTarget.style.opacity = opacity;
        }
    }

    // Phase 3: Finger los — schließen oder zurücksnappen?
    onSwipeEnd(event) {
        if (this.swipeStartY === undefined) return;

        const endY = event.changedTouches[0].clientY;
        const deltaY = endY - this.swipeStartY;

        // Transition wieder aktivieren für die Animation
        this.bottomSheetTarget.style.transition = '';
        this.sheetBackdropTarget.style.opacity = '';

        // Schwellenwert: 80px nach unten gezogen → schließen
        if (deltaY > 80) {
            this.closeSheet();
        } else {
            // Nicht weit genug → zurücksnappen
            this.bottomSheetTarget.style.transform = '';
        }

        this.swipeStartY = undefined;
    }

    // --- Letzte 10 Konzerte als Liste unter der Karte ---
    renderConcertList(concerts) {
        const sorted = this.sortByDate(concerts);

        this.concertListTarget.innerHTML = sorted.slice(0, 5).map(concert => `
            <a href="/metallica/setlist/${concert.id}"
               class="flex items-center justify-between rounded-xl border border-warm-200 p-4 transition-colors hover:border-accent-500 hover:bg-warm-50">
                <div>
                    <p class="font-medium text-warm-900">${concert.venue}</p>
                    <p class="mt-0.5 text-sm text-warm-500">${concert.city}, ${concert.country}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-warm-500">${concert.date}</p>
                    ${concert.tour ? '<p class="text-xs text-warm-400">' + concert.tour + '</p>' : ''}
                </div>
            </a>
        `).join('');
    }
}
