<?php

namespace App\Controller;

use App\Service\SetlistFmService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Controller für die Metallica Universe Seite.
// Liefert die HTML-Seite und die Konzertdaten als JSON für die Karte.
#[Route('/metallica')]
class MetallicaController extends AbstractController
{
    // ---------- Hauptseite mit Karte ----------
    #[Route('', name: 'app_metallica')]
    public function index(): Response
    {
        return $this->render('metallica/index.html.twig');
    }

    // ---------- JSON-Endpoint für die Kartendaten ----------
    // Die Karte (Leaflet.js) holt sich die Konzertdaten per AJAX von diesem Endpoint.
    // Warum JSON statt direkt im Template? Weil das Laden aller 2000+ Konzerte
    // ein paar Sekunden dauern kann — so kann die Seite schon rendern
    // während die Daten im Hintergrund geladen werden.
    #[Route('/api/concerts', name: 'app_metallica_concerts')]
    public function concerts(SetlistFmService $setlistFm): JsonResponse
    {
        return $this->json($setlistFm->getAllConcertsForMap());
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
}
