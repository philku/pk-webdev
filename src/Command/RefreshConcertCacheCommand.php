<?php

namespace App\Command;

use App\Service\SetlistFmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Symfony Console Command zum Auffrischen des Konzert-Caches.
// Kann manuell oder per Cron-Job aufgerufen werden:
//
//   php bin/console app:concerts:refresh
//
// Auf dem VPS z.B. wöchentlich per Cron:
//   0 3 * * 1  cd /path/to/project && php bin/console app:concerts:refresh
//
// Was passiert:
//   1. Full-Cache vorhanden → 1 API-Call, prüft ob neue Konzerte da sind
//   2. Kein Cache → Alle ~109 Seiten laden (Cold Start, dauert ~2 Minuten)
#[AsCommand(
    name: 'app:concerts:refresh',
    description: 'Frischt den Konzert-Cache auf (Delta-Check oder Full-Rebuild)',
)]
class RefreshConcertCacheCommand extends Command
{
    public function __construct(
        private SetlistFmService $setlistFm,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle = hübsche Console-Ausgabe mit Farben, Tabellen, etc.
        $io = new SymfonyStyle($input, $output);

        $io->title('Konzert-Cache Refresh');

        // --- Versuch 1: Delta-Check (schnell, 1 API-Call) ---
        // Wenn ein Full-Cache existiert, prüft checkForNewConcerts()
        // ob sich das total geändert hat und aktualisiert ggf.
        $result = $this->setlistFm->checkForNewConcerts();

        if ($result !== null) {
            $io->success(sprintf(
                'Cache aktuell — %s Konzerte (TTL auf 30 Tage zurückgesetzt)',
                number_format(count($result), 0, ',', '.')
            ));
            return Command::SUCCESS;
        }

        // --- Versuch 2: Cold Start (langsam, alle Seiten laden) ---
        // Kein Full-Cache vorhanden → alle Seiten progressiv laden.
        $io->warning('Kein Cache vorhanden — starte Full-Rebuild...');

        // Erste Seite holen um totalPages zu erfahren
        $firstPage = $this->setlistFm->getMapConcertsPage(1);
        $totalPages = $firstPage['totalPages'];
        $total = $firstPage['total'];

        $io->progressStart($totalPages);
        $io->progressAdvance(); // Seite 1 ist schon geladen

        // Restliche Seiten laden (mit 1s Pause wegen Rate Limit)
        for ($page = 2; $page <= $totalPages; $page++) {
            $this->setlistFm->getMapConcertsPage($page);
            $io->progressAdvance();
        }

        $io->progressFinish();

        // Full-Cache aus den Seiten-Caches zusammenbauen
        $this->setlistFm->buildFullMapCache($totalPages, $total);

        $io->success(sprintf(
            'Full-Rebuild abgeschlossen — %s Konzerte auf %d Seiten gecacht',
            number_format($total, 0, ',', '.'),
            $totalPages
        ));

        return Command::SUCCESS;
    }
}
