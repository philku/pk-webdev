<?php

namespace App\Controller;

use App\Service\SetlistFmService;
use App\Service\SpotifyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Controller für die Metallica Universe Seite.
// Liefert die HTML-Seite, Konzertdaten, und Discography-Daten.
#[Route('/metallica')]
class MetallicaController extends AbstractController
{
    // ---------- Hauptseite: Karte + Discography ----------
    // Kombiniert Konzertkarte und Discography auf einer Seite.
    // Spotify-Daten (Alben, Artist-Info) werden server-side gerendert,
    // die Konzertdaten kommen asynchron per JSON (Stimulus Controller).
    #[Route('', name: 'app_metallica')]
    public function index(SpotifyService $spotify): Response
    {
        $albums = $spotify->getAlbums();

        return $this->render('metallica/index.html.twig', [
            'albums' => $albums,
        ]);
    }

    // ---------- JSON-Endpoint für die Kartendaten ----------
    // Zwei Modi:
    //   1. Ohne ?page → prüft ob Full-Cache existiert → wenn ja, alles auf einmal
    //   2. Mit ?page=X → eine Seite (progressives Laden)
    // Beim letzten ?page-Request wird automatisch der Full-Cache gebaut,
    // damit der nächste Besuch sofort alles auf einen Schlag bekommt.
    #[Route('/api/concerts', name: 'app_metallica_concerts')]
    public function concerts(SetlistFmService $setlistFm, Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 0);

        // --- Modus 1: Paginierter Request (?page=X) ---
        if ($page > 0) {
            $data = $setlistFm->getMapConcertsPage($page);

            // Wenn das die letzte Seite war → Full-Cache für nächsten Besuch bauen.
            // buildFullMapCache() liest nur aus den Seiten-Caches (keine API-Calls),
            // weil zu diesem Zeitpunkt alle Seiten bereits einzeln gecacht sind.
            if ($page >= $data['totalPages']) {
                $setlistFm->buildFullMapCache($data['totalPages']);
            }

            return $this->json($data);
        }

        // --- Modus 2: Kein ?page → Full-Cache versuchen ---
        $allConcerts = $setlistFm->getFullMapCacheIfAvailable();

        if ($allConcerts !== null) {
            // Full-Cache existiert → alles auf einen Schlag zurückgeben.
            // 'complete' => true sagt dem Frontend: keine weitere Pagination nötig.
            return $this->json([
                'concerts' => $allConcerts,
                'complete' => true,
            ]);
        }

        // Kein Full-Cache → erste Seite liefern + Signal für progressives Laden.
        // Das Frontend sieht 'complete' => false und startet die Pagination ab Seite 2.
        $data = $setlistFm->getMapConcertsPage(1);
        $data['complete'] = false;

        return $this->json($data);
    }

    // ---------- Setlist-Detail ----------
    // Zeigt die komplette Setlist eines einzelnen Konzerts.
    #[Route('/setlist/{id}', name: 'app_metallica_setlist')]
    public function setlist(string $id, SetlistFmService $setlistFm): Response
    {
        $setlist = $setlistFm->getSetlist($id);

        if (!$setlist) {
            throw $this->createNotFoundException('Setlist nicht gefunden.');
        }

        return $this->render('metallica/setlist.html.twig', [
            'setlist' => $setlist,
        ]);
    }

    // ---------- Discography: Album-Detail mit Tracklist ----------
    #[Route('/discography/album/{id}', name: 'app_metallica_album')]
    public function album(string $id, SpotifyService $spotify): Response
    {
        $album = $spotify->getAlbum($id);

        return $this->render('metallica/album.html.twig', [
            'album' => $album,
        ]);
    }

    // ---------- Meistgespielte Songs als JSON ----------
    // Liefert die Top 20 Songs nach Live-Play-Count für den Stimulus Controller.
    #[Route('/api/discography/stats', name: 'app_metallica_discography_stats')]
    public function discographyStats(SetlistFmService $setlistFm): JsonResponse
    {
        $playCounts = $setlistFm->getSongPlayCounts();

        $topPlayed = [];
        foreach (array_slice($playCounts, 0, 20, true) as $song => $count) {
            $topPlayed[] = [
                'name' => $song,
                'count' => $count,
            ];
        }

        return $this->json([
            'topPlayed' => $topPlayed,
        ]);
    }
}
