<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Etsy;

use App\Application\Integration\ConnectionSession;
use App\Domain\Identity\WorkspaceRepository;
use App\Domain\Integration\ServiceConnection;
use App\Domain\Shared\Exception\DomainException;
use App\Infrastructure\Http\Json;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:etsy:receipt', description: 'Prints everything Etsy returns for one order (receipt, payments, ledger entries tied to it) and the fee the import reads from them')]
final readonly class ShowEtsyReceiptCommand
{
    public function __construct(
        private WorkspaceRepository $workspaces,
        private EntityManagerInterface $entityManager,
        private ConnectionSession $session,
        private EtsyApiGateway $gateway,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Etsy order number (receipt id), e.g. 3456789012 or ETSY-3456789012')]
        string $number,
        #[Option(description: 'Workspace whose Etsy shop is read')]
        string $workspace = '',
    ): int {
        $found = $this->workspaces->findByName(trim($workspace));
        if (null === $found) {
            $io->error(\sprintf('No workspace named "%s".', $workspace));

            return Command::INVALID;
        }

        $connection = $this->entityManager->getRepository(ServiceConnection::class)->findOneBy(['workspace' => $found, 'service' => EtsyConnector::KEY]);
        if (null === $connection) {
            $io->error('Etsy is not added to this workspace.');

            return Command::FAILURE;
        }

        $receiptId = (string) preg_replace('/^ETSY-/i', '', trim($number));
        try {
            $credentials = $this->session->credentials($connection);
            $app = new EtsyApp($credentials->get('keystring'), $credentials->get('shared_secret'));
            $accessToken = (string) $credentials->accessToken;
            $shopId = (string) $credentials->accountId;
            $receipt = $this->gateway->receipt($app, $accessToken, $shopId, $receiptId);
            $payments = $this->gateway->receiptPayments($app, $accessToken, $shopId, $receiptId);
            $entries = $this->gateway->ledgerEntries($app, $accessToken, $shopId, (int) Json::number($receipt['create_timestamp'] ?? $receipt['created_timestamp'] ?? 0));
        } catch (DomainException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $fees = new EtsyFees([$receipt], $entries, $payments);
        $io->writeln((string) json_encode([
            'receipt' => $receipt,
            'payments' => $payments,
            'ledger_entries' => $fees->entriesOf($receiptId),
            'fee_cents' => $fees->of($receiptId)?->amount(),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
