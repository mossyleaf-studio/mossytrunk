<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Connector;

use App\Application\Integration\ConfigureConnection\AddConnectionHandler;
use App\Application\Workspace\WorkspaceSecrets;
use App\Domain\Identity\WorkspaceRepository;
use App\Infrastructure\Connector\SumUp\ShowSumUpTransactionCommand;
use App\Tests\Support\ActsAsUser;
use App\Tests\Support\ExternalSales;
use App\Tests\Support\Json;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShowSumUpTransactionCommandTest extends KernelTestCase
{
    use ActsAsUser;

    public function testPrintsTheProductsSumUpReturnsWithoutCardDetails(): void
    {
        self::actAsMemberOf('Atelier');
        ExternalSales::connect(self::getContainer()->get(AddConnectionHandler::class), 'sumup', ['merchant_code' => 'MCODE', 'api_key' => 'sup_sk_test']);
        $requested = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requested): MockResponse {
            $requested = [$url, Json::at($options, 'normalized_headers', 'authorization', 0)];

            return new JsonMockResponse([
                'transaction_code' => 'TAAA6MPKY9S',
                'amount' => 3.0,
                'payment_type' => 'ECOM',
                'entry_mode' => 'CUSTOMER_ENTRY',
                'events' => [['type' => 'PAYOUT', 'fee_amount' => 0.05]],
                'card' => ['last_4_digits' => '4242'],
                'products' => [['name' => 'Eevee Aquali', 'price_label' => 'Sticker', 'price_with_vat' => 3.0, 'quantity' => 1]],
            ]);
        }, 'https://api.sumup.com');
        $command = new ShowSumUpTransactionCommand(self::getContainer()->get(WorkspaceRepository::class), self::getContainer()->get(WorkspaceSecrets::class), self::getContainer()->get(EntityManagerInterface::class), $client);
        $output = new BufferedOutput();

        $status = $command(new SymfonyStyle(new ArrayInput([]), $output), 'TAAA6MPKY9S', 'Atelier');

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('https://api.sumup.com/v2.1/merchants/MCODE/transactions?transaction_code=TAAA6MPKY9S', $requested[0]);
        self::assertSame('Authorization: Bearer sup_sk_test', $requested[1]);
        $printed = $output->fetch();
        self::assertStringContainsString('"price_label": "Sticker"', $printed);
        self::assertStringContainsString('"fee_amount": 0.05', $printed);
        self::assertStringContainsString('"payment_type": "ECOM"', $printed);
        self::assertStringContainsString('"entry_mode": "CUSTOMER_ENTRY"', $printed);
        self::assertStringNotContainsString('4242', $printed);
    }

    public function testRefusesAnUnknownWorkspace(): void
    {
        $command = new ShowSumUpTransactionCommand(self::getContainer()->get(WorkspaceRepository::class), self::getContainer()->get(WorkspaceSecrets::class), self::getContainer()->get(EntityManagerInterface::class), new MockHttpClient());
        $output = new BufferedOutput();

        self::assertSame(Command::INVALID, $command(new SymfonyStyle(new ArrayInput([]), $output), 'T1', 'Inconnu'));
        self::assertStringNotContainsString('4242', $output->fetch());
    }
}
