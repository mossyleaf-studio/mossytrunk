<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\SumUp;

use App\Application\Workspace\WorkspaceSecrets;
use App\Domain\Identity\SecretName;
use App\Domain\Identity\WorkspaceRepository;
use App\Domain\Integration\ServiceConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:sumup:transaction', description: 'Prints what SumUp returns for one transaction (amounts, fees and products), to check how the import reads it')]
final readonly class ShowSumUpTransactionCommand
{
    private const array SHOWN_FIELDS = ['transaction_code', 'timestamp', 'amount', 'tip_amount', 'currency', 'status', 'payment_type', 'entry_mode', 'products', 'events'];

    public function __construct(
        private WorkspaceRepository $workspaces,
        private WorkspaceSecrets $secrets,
        private EntityManagerInterface $entityManager,
        #[Target('sumup.client')]
        private HttpClientInterface $sumUp,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'SumUp transaction code, e.g. TAAA6MPKY9S')]
        string $code,
        #[Option(description: 'Workspace whose SumUp settings are used')]
        string $workspace = '',
    ): int {
        $found = $this->workspaces->findByName(trim($workspace));
        if (null === $found) {
            $io->error(\sprintf('No workspace named "%s".', $workspace));

            return Command::INVALID;
        }

        $connection = $this->entityManager->getRepository(ServiceConnection::class)->findOneBy(['workspace' => $found, 'service' => SumUpConnector::KEY]);
        $apiKey = $this->secrets->reveal($found, SecretName::of(SumUpConnector::KEY, 'api_key'));
        $merchantCode = $connection?->setting('merchant_code');
        if (null === $apiKey || null === $merchantCode) {
            $io->error('SumUp is not configured for this workspace.');

            return Command::FAILURE;
        }

        try {
            $transaction = $this->sumUp->request('GET', \sprintf('/v2.1/merchants/%s/transactions', rawurlencode($merchantCode)), [
                'auth_bearer' => $apiKey,
                'query' => ['transaction_code' => $code],
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln((string) json_encode(array_intersect_key($transaction, array_flip(self::SHOWN_FIELDS)), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
