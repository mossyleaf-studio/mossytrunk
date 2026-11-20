<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\SumUp;

use App\Application\Integration\ExternalSale;
use App\Infrastructure\Http\Json;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FakeSumUpGateway implements SumUpGateway
{
    /** @var list<ExternalSale>|null */
    private ?array $transactions = null;

    public function __construct(
        private readonly SumUpPayloadMapper $mapper,
        #[Autowire('%kernel.project_dir%/tests/Fixtures/sumup/transactions.json')]
        private readonly string $fixture,
    ) {
    }

    /**
     * @param list<ExternalSale> $transactions
     */
    public function willReturn(array $transactions): void
    {
        $this->transactions = $transactions;
    }

    public function successfulPayments(SumUpCredentials $credentials): iterable
    {
        if (null !== $this->transactions) {
            return $this->transactions;
        }

        $payload = json_decode((string) file_get_contents($this->fixture), true, flags: \JSON_THROW_ON_ERROR);

        $terminalPayments = array_filter(Json::objects(Json::object($payload)['items'] ?? []), fn (array $item): bool => !$this->mapper->paidOnline($item));

        return array_map($this->mapper->sale(...), array_values($terminalPayments));
    }
}
