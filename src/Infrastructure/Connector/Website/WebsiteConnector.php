<?php

declare(strict_types=1);

namespace App\Infrastructure\Connector\Website;

use App\Application\Integration\CatalogueChanges;
use App\Application\Integration\CataloguePublishing;
use App\Application\Integration\Credentials;
use App\Application\Integration\LinePrices;
use App\Application\Integration\ServiceDescription;
use App\Application\Integration\ServiceField;
use App\Application\Translator;
use App\Domain\Integration\SalesContext;
use App\Domain\Integration\UnknownItems;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class WebsiteConnector implements CataloguePublishing
{
    public const string KEY = 'website';

    public function __construct(
        private WebsiteGateway $gateway,
        private Translator $translator,
        #[Autowire(param: 'app.website.address_pattern')]
        private string $addressPattern,
    ) {
    }

    public function describe(): ServiceDescription
    {
        return new ServiceDescription(
            self::KEY,
            $this->label(),
            'services.website.summary',
            [
                new ServiceField('address', 'services.website.fields.address', pattern: $this->addressPattern, patternMessage: 'services.fields.httpsOrigin', hint: 'services.website.fields.addressHint', bindsSecrets: true),
                new ServiceField('token', 'services.website.fields.token', secret: true, pattern: '/^[\x21-\x7E]+\z/', patternMessage: 'services.fields.bearerToken', hint: 'services.website.fields.tokenHint', maxLength: 500),
            ],
            SalesContext::Online,
            UnknownItems::LinkByHand,
            LinePrices::Listed,
        );
    }

    public function sales(Credentials $credentials): iterable
    {
        return $this->gateway->paidOrders($this->access($credentials));
    }

    public function publishCatalogue(Credentials $credentials, array $products): CatalogueChanges
    {
        return $this->gateway->replaceCatalogue($this->access($credentials), $products);
    }

    private function access(Credentials $credentials): WebsiteAccess
    {
        return new WebsiteAccess($credentials->get('address'), $credentials->get('token'), $this->label());
    }

    private function label(): string
    {
        return $this->translator->trans('services.website.label');
    }
}
