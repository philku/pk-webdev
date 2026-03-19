<?php

namespace App\Controller;

use App\Service\SetlistFmService;
use App\Service\SpotifyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/metallica')]
class MetallicaController extends AbstractController
{
    // Combines concert map and discography on one page.
    // Spotify data (albums) is server-rendered; concerts load async via Stimulus.
    #[Route('', name: 'app_metallica')]
    public function index(SpotifyService $spotify): Response
    {
        try {
            $albums = $spotify->getAlbums();
        } catch (\Throwable) {
            $albums = [];
        }

        return $this->render('metallica/index.html.twig', [
            'albums' => $albums,
        ]);
    }

    // JSON endpoint for concert map data. Three modes:
    //   1. ?page=X → paginated (progressive loading on cold start)
    //   2. No ?page + full cache → delta check (1 API call), merge new concerts
    //   3. No ?page + no cache → return page 1, frontend loads remaining pages
    #[Route('/api/concerts', name: 'app_metallica_concerts')]
    public function concerts(SetlistFmService $setlistFm, Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 0);

        if ($page > 0) {
            $data = $setlistFm->getMapConcertsPage($page);

            // Last page reached — build full cache from page caches (no API calls).
            if ($page >= $data['totalPages']) {
                $setlistFm->buildFullMapCache($data['totalPages'], $data['total']);
            }

            return $this->json($data);
        }

        // Full cache exists → delta check (compare totals, merge if needed).
        $allConcerts = $setlistFm->checkForNewConcerts();

        if ($allConcerts !== null) {
            return $this->json([
                'concerts' => $allConcerts,
                'complete' => true,
            ]);
        }

        // Cold start → progressive loading from page 1.
        $data = $setlistFm->getMapConcertsPage(1);
        $data['complete'] = false;

        return $this->json($data);
    }

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

    // Top 10 most-played songs by live play count.
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
