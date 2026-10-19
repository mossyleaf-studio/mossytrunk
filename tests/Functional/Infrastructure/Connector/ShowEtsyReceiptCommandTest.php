<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Connector;

use App\Application\Integration\CompleteAuthorization\CompleteAuthorizationHandler;
use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Integration\ConnectionSession;
use App\Domain\Identity\WorkspaceRepository;
use App\Infrastructure\Connector\Etsy\EtsyApiGateway;
use App\Infrastructure\Connector\Etsy\EtsyPayloadMapper;
use App\Infrastructure\Connector\Etsy\ShowEtsyReceiptCommand;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\ExternalSales;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShowEtsyReceiptCommandTest extends KernelTestCase
{
    use ActsAsUser;

    public function testPrintsTheReceiptItsPaymentsItsLedgerEntriesAndTheFee(): void
    {
        self::actAsMemberOf('Atelier');
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'etsy', ['keystring' => 'keystring123', 'shared_secret' => 'shared-secret']);
        self::getContainer()->get(CompleteAuthorizationHandler::class)('etsy', 'code', 'verifier', 'https://app.test/settings/etsy/callback');
        $requested = [];
        $responses = [
            new JsonMockResponse(['receipt_id' => 3_100_000_001, 'status' => 'Completed', 'create_timestamp' => 1_893_456_000, 'transactions' => [['transaction_id' => 11, 'title' => 'Sticker Mousse']]]),
            new JsonMockResponse(['count' => 1, 'results' => [['payment_id' => 91, 'receipt_id' => 3_100_000_001, 'amount_fees' => ['amount' => 46]]]]),
            new JsonMockResponse(['results' => [
                ['entry_id' => 1, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '11', 'amount' => -26],
                ['entry_id' => 2, 'ledger_type' => 'PAYMENT_PROCESSING_FEE', 'reference_type' => 'payment', 'reference_id' => '91', 'amount' => -46],
                ['entry_id' => 3, 'ledger_type' => 'transaction', 'reference_type' => 'transaction', 'reference_id' => '99', 'amount' => -500],
            ]]),
        ];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requested, &$responses): MockResponse {
            $requested[] = $url;

            return array_shift($responses) ?? throw new \LogicException('Unexpected Etsy request.');
        }, 'https://api.etsy.com');
        $output = new BufferedOutput();

        $status = $this->command($client, new MockClock('@'.(1_893_456_000 + 86_400)))(new SymfonyStyle(new ArrayInput([]), $output), 'ETSY-3100000001', 'Atelier');

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringEndsWith('/shops/12345678/receipts/3100000001', $requested[0]);
        self::assertStringEndsWith('/shops/12345678/receipts/3100000001/payments', $requested[1]);
        $printed = $output->fetch();
        self::assertStringContainsString('"title": "Sticker Mousse"', $printed);
        self::assertStringContainsString('"amount_fees"', $printed);
        self::assertStringContainsString('"PAYMENT_PROCESSING_FEE"', $printed);
        self::assertStringNotContainsString('"reference_id": "99"', $printed);
        self::assertStringContainsString('"fee_cents": 72', $printed);
    }

    public function testRefusesAWorkspaceWithoutEtsy(): void
    {
        self::actAsMemberOf('Atelier');
        $output = new BufferedOutput();

        self::assertSame(Command::FAILURE, $this->command(new MockHttpClient(), new MockClock())(new SymfonyStyle(new ArrayInput([]), $output), '1', 'Atelier'));
        self::assertStringContainsString('Etsy is not added', $output->fetch());
    }

    private function command(MockHttpClient $client, ClockInterface $clock): ShowEtsyReceiptCommand
    {
        return new ShowEtsyReceiptCommand(
            self::getContainer()->get(WorkspaceRepository::class),
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(ConnectionSession::class),
            new EtsyApiGateway($client, new EtsyPayloadMapper(), $clock),
        );
    }
}
