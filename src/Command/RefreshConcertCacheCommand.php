<?php

namespace App\Command;

use App\Service\SetlistFmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Delta-checks existing cache (1 API call) or full-rebuilds on cold start.
#[AsCommand(
    name: 'app:concerts:refresh',
    description: 'Refresh concert cache (delta check or full rebuild)',
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
        $io = new SymfonyStyle($input, $output);
        $io->title('Concert Cache Refresh');

        $result = $this->setlistFm->checkForNewConcerts();

        if ($result !== null) {
            $io->success(sprintf(
                'Cache up to date — %s concerts (TTL reset to 30 days)',
                number_format(count($result), 0, ',', '.')
            ));
            return Command::SUCCESS;
        }

        $io->warning('No cache found — starting full rebuild...');

        $firstPage = $this->setlistFm->getMapConcertsPage(1);
        $totalPages = $firstPage['totalPages'];
        $total = $firstPage['total'];

        $io->progressStart($totalPages);
        $io->progressAdvance();

        for ($page = 2; $page <= $totalPages; $page++) {
            $this->setlistFm->getMapConcertsPage($page);
            $io->progressAdvance();
        }

        $io->progressFinish();

        $this->setlistFm->buildFullMapCache($totalPages, $total);

        $io->success(sprintf(
            'Full rebuild complete — %s concerts across %d pages cached',
            number_format($total, 0, ',', '.'),
            $totalPages
        ));

        return Command::SUCCESS;
    }
}
