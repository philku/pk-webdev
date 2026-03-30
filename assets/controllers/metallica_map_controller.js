import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

// Leaflet's auto-detection of icon URLs fails with ES module imports.
delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({
    iconUrl: '/images/marker-icon.png',
    iconRetinaUrl: '/images/marker-icon-2x.png',
    shadowUrl: '/images/marker-shadow.png',
});

// Concert world map with progressive loading, clustering, and mobile bottom sheet.
export default class extends Controller {
    static targets = [
        'mapContainer',
        'loading',
        'concertList',
        'statTotal',
        'statCountries',
        'statCities',
        'statYears',
        'progress',
        'progressBar',
        'progressText',
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

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18,
        }).addTo(this.map);

        this.markers = L.layerGroup().addTo(this.map);
        this.loadConcerts();
    }

    // Abort in-flight requests on Turbo navigation to prevent DOM errors.
    disconnect() {
        if (this.abortController) {
            this.abortController.abort();
        }
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
    }

    // Bottom sheet on mobile instead of tiny Leaflet popups.
    get isMobile() {
        return window.innerWidth < 640;
    }

    // Full cache → instant load. No cache → progressive page-by-page loading.
    async loadConcerts() {
        this.abortController = new AbortController();
        this.allConcerts = [];

        try {
            const initialData = await this.fetchUrl(this.urlValue);

            this.loadingTarget.classList.add('hidden');
            this.mapContainerTarget.classList.remove('hidden');
            this.map.invalidateSize();

            if (initialData.complete) {
                this.allConcerts = initialData.concerts;
                this.refreshMap();
                return;
            }

            this.allConcerts = [...initialData.concerts];
            this.refreshMap();

            const totalPages = initialData.totalPages;

            if (totalPages > 1) {
                this.showProgress(1, totalPages, initialData.total);

                // Sequential — setlist.fm rate limit is ~2 req/s.
                for (let page = 2; page <= totalPages; page++) {
                    const data = await this.fetchPage(page);

                    this.allConcerts.push(...data.concerts);
                    this.refreshMap();
                    this.showProgress(page, totalPages, initialData.total);
                }

                this.hideProgress();
            }
        } catch (error) {
            if (error.name === 'AbortError') return;

            this.loadingTarget.innerHTML = `
                <p class="text-sm text-red-600">Fehler beim Laden der Konzertdaten.</p>
            `;
            console.error('Error:', error);
        }
    }

    async fetchUrl(url) {
        const response = await fetch(url, {
            signal: this.abortController.signal,
        });
        return response.json();
    }

    async fetchPage(page) {
        return this.fetchUrl(`${this.urlValue}?page=${page}`);
    }

    // Full rebuild per page — grouping changes as new concerts arrive.
    refreshMap() {
        this.markers.clearLayers();
        this.addMarkers(this.allConcerts);
        this.updateStats(this.allConcerts);
        this.renderConcertList(this.allConcerts);
    }

    showProgress(currentPage, totalPages, total) {
        this.progressTarget.classList.remove('hidden');

        const percent = Math.round((currentPage / totalPages) * 100);
        this.progressBarTarget.style.width = `${percent}%`;

        this.progressTextTarget.textContent =
            `${this.allConcerts.length.toLocaleString('de-DE')} von ~${total.toLocaleString('de-DE')} Konzerten`;
    }

    hideProgress() {
        this.progressTarget.classList.add('hidden');
    }

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

    sortByDate(concerts) {
        return [...concerts].sort((a, b) => {
            const [dA, mA, yA] = a.date.split('-').map(Number);
            const [dB, mB, yB] = b.date.split('-').map(Number);
            return new Date(yB, mB - 1, dB) - new Date(yA, mA - 1, dA);
        });
    }

    // Groups concerts by coordinates — same venue gets one marker with a list.
    addMarkers(concerts) {
        const groups = {};
        concerts.forEach(concert => {
            const key = concert.lat + ',' + concert.lng;
            if (!groups[key]) groups[key] = [];
            groups[key].push(concert);
        });

        Object.entries(groups).forEach(([key, groupConcerts]) => {
            const [lat, lng] = key.split(',').map(Number);
            const first = groupConcerts[0];

            const marker = L.marker([lat, lng]);

            marker.on('click', () => {
                if (this.isMobile) {
                    this.openSheet(first, groupConcerts);
                } else {
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

    buildPopupHtml(first, groupConcerts) {
        if (groupConcerts.length === 1) {
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

    openSheet(venue, groupConcerts) {
        this.map.closePopup();
        this.sheetTitleTarget.textContent = venue.venue;
        this.sheetSubtitleTarget.textContent = `${venue.city}, ${venue.country}`;

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

        this.bottomSheetTarget.classList.remove('translate-y-full');
        this.bottomSheetTarget.classList.add('translate-y-0');

        this.sheetBackdropTarget.classList.remove('opacity-0', 'pointer-events-none');
        this.sheetBackdropTarget.classList.add('opacity-100');

        document.body.style.overflow = 'hidden';
    }

    closeSheet() {
        this.bottomSheetTarget.classList.add('translate-y-full');
        this.bottomSheetTarget.classList.remove('translate-y-0');
        this.bottomSheetTarget.style.transform = '';

        this.sheetBackdropTarget.classList.add('opacity-0', 'pointer-events-none');
        this.sheetBackdropTarget.classList.remove('opacity-100');

        document.body.style.overflow = '';
    }

    // Swipe-to-close: >80px down dismisses, otherwise snaps back.
    onSwipeStart(event) {
        this.swipeStartY = event.touches[0].clientY;
        // Disable transition so sheet follows finger without lag.
        this.bottomSheetTarget.style.transition = 'none';
    }

    onSwipeMove(event) {
        if (this.swipeStartY === undefined) return;

        const currentY = event.touches[0].clientY;
        const deltaY = currentY - this.swipeStartY;

        // Only allow downward drag.
        if (deltaY > 0) {
            this.bottomSheetTarget.style.transform = `translateY(${deltaY}px)`;
            const opacity = Math.max(0, 1 - deltaY / 300);
            this.sheetBackdropTarget.style.opacity = opacity;
        }
    }

    onSwipeEnd(event) {
        if (this.swipeStartY === undefined) return;

        const endY = event.changedTouches[0].clientY;
        const deltaY = endY - this.swipeStartY;

        this.bottomSheetTarget.style.transition = '';
        this.sheetBackdropTarget.style.opacity = '';

        if (deltaY > 80) {
            this.closeSheet();
        } else {
            this.bottomSheetTarget.style.transform = '';
        }

        this.swipeStartY = undefined;
    }

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
