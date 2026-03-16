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
        // try/catch: Wenn Spotify down ist oder 400/500 zurückgibt,
        // zeigt die Seite einfach keine Alben an statt komplett zu crashen.
        // Karte und Songs funktionieren trotzdem (kommen von setlist.fm).
        try {
            $albums = $spotify->getAlbums();
        } catch (\Throwable) {
            $albums = [];
        }

        return $this->render('metallica/index.html.twig', [
            'albums' => $albums,
        ]);
    }

    // ---------- JSON-Endpoint für die Kartendaten ----------
    // Drei Modi:
    //   1. Mit ?page=X → eine Seite (progressives Laden bei Cold Start)
    //   2. Ohne ?page + Full-Cache → Delta-Check (1 API-Call), ggf. neue Konzerte nachladen
    //   3. Ohne ?page + kein Cache → erste Seite liefern, Frontend lädt progressiv nach
    #[Route('/api/concerts', name: 'app_metallica_concerts')]
    public function concerts(SetlistFmService $setlistFm, Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 0);

        // --- Modus 1: Paginierter Request (?page=X) ---
        // Wird vom Frontend beim progressiven Laden benutzt (Cold Start).
        if ($page > 0) {
            $data = $setlistFm->getMapConcertsPage($page);

            // Letzte Seite erreicht → Full-Cache für nächsten Besuch bauen.
            // buildFullMapCache() liest nur aus den Seiten-Caches (keine API-Calls).
            if ($page >= $data['totalPages']) {
                $setlistFm->buildFullMapCache($data['totalPages'], $data['total']);
            }

            return $this->json($data);
        }

        // --- Modus 2: Kein ?page → Full-Cache + Delta-Check ---
        // checkForNewConcerts() macht intern:
        //   - Full-Cache lesen → total vergleichen (1 API-Call)
        //   - Gleich → TTL refreshen (30 Tage neu), Cache zurückgeben
        //   - Höher → Delta-Konzerte nachladen, Cache erweitern
        //   - Kein Cache → null (Fall-through zu Progressive Loading)
        $allConcerts = $setlistFm->checkForNewConcerts();

        if ($allConcerts !== null) {
            return $this->json([
                'concerts' => $allConcerts,
                'complete' => true,
            ]);
        }

        // --- Modus 3: Kein Cache (Cold Start) → Progressive Loading ---
        // Erste Seite liefern + Signal für progressives Laden.
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
        try {
            $album = $spotify->getAlbum($id);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Album nicht verfügbar.');
        }

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
        foreach (array_slice($playCounts, 0, 10, true) as $song => $count) {
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
