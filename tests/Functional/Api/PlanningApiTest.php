<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Infrastructure\Security\SecurityUser;
use App\Tests\Support\Json;
use App\Tests\Support\SignsInClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanningApiTest extends WebTestCase
{
    use SignsInClient;

    public function testPlanningNeedsASession(): void
    {
        $client = self::createClient();

        $client->jsonRequest('GET', '/api/planning');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheTemplateRoundTrips(): void
    {
        $client = self::signedInClient();

        $client->jsonRequest('GET', '/api/release-template');
        self::assertSame([], Json::array(self::body($client), 'steps'));

        $client->jsonRequest('PUT', '/api/release-template', ['steps' => [['label' => ' Dessins ', 'days' => 3], ['label' => 'Vernis', 'days' => 1]]]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', '/api/release-template');
        self::assertSame('Dessins', Json::at(self::body($client), 'steps', 0, 'label'));
        self::assertSame(1, Json::at(self::body($client), 'steps', 1, 'days'));
    }

    public function testACollectionReleaseIsPlannedReplannedTickedAndCancelled(): void
    {
        $client = self::signedInClient();
        $collectionId = self::collection($client);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => [['label' => 'Dessins', 'days' => 3]]]);
        self::assertResponseStatusCodeSame(201);
        $releaseId = Json::string(self::body($client), 'id');

        $client->jsonRequest('GET', '/api/planning');
        self::assertSame('2099-06-10', Json::at(self::body($client), 'releases', 0, 'day'));
        self::assertSame('Sous-bois', Json::at(self::body($client), 'releases', 0, 'owner', 'name'));

        $client->jsonRequest('PUT', "/api/releases/$releaseId", ['day' => '2099-07-01', 'steps' => [['label' => 'Dessins', 'days' => 2], ['label' => 'Vernis', 'days' => 1]]]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('PUT', "/api/releases/$releaseId/steps", ['step' => 'Vernis', 'done' => true]);
        self::assertResponseStatusCodeSame(204);

        $client->jsonRequest('GET', '/api/planning');
        self::assertSame('2099-07-01', Json::at(self::body($client), 'releases', 0, 'day'));
        self::assertTrue(Json::at(self::body($client), 'releases', 0, 'steps', 1, 'done'));

        $client->jsonRequest('DELETE', "/api/releases/$releaseId");
        self::assertResponseStatusCodeSame(204);
        $client->jsonRequest('GET', '/api/planning');
        self::assertSame([], Json::array(self::body($client), 'releases'));
    }

    public function testInvalidReleasesAreRejected(): void
    {
        $client = self::signedInClient();
        $collectionId = self::collection($client);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => [['label' => ' ', 'days' => 1]]]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => [['label' => 'Dessins', 'days' => 0]]]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['steps' => []]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => [['label' => 'Dessins', 'days' => 1], ['label' => ' Dessins', 'days' => 2]]]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => [['label' => 'Dessins', 'days' => 'abc']]]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['eventId' => 'not-a-ulid', 'steps' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('eventId', Json::at(self::body($client), 'violations', 0, 'propertyPath'));
    }

    public function testAReleaseOfAnotherWorkspaceIsNotFound(): void
    {
        $client = self::signedInClient('Atelier');
        $collectionId = self::collection($client);
        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => []]);
        $releaseId = Json::string(self::body($client), 'id');

        $client->loginUser(SecurityUser::fromUser(self::createMember('Autre atelier')));
        $client->jsonRequest('DELETE', "/api/releases/$releaseId");

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOwnerIsPlannedOnce(): void
    {
        $client = self::signedInClient();
        $collectionId = self::collection($client);
        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => []]);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-07-10', 'steps' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Sous-bois', Json::string(self::body($client), 'detail'));
    }

    public function testADesignIsPlannedOnItsOwn(): void
    {
        $client = self::signedInClient();
        $client->jsonRequest('POST', '/api/designs', ['name' => 'Héron']);
        $designId = Json::string(self::body($client), 'id');

        $client->jsonRequest('POST', "/api/designs/$designId/release", ['day' => '2099-06-10', 'steps' => [['label' => 'Croquis', 'days' => 2]]]);
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest('GET', "/api/designs/$designId/release");
        self::assertFalse(self::body($client)['inherited']);
        self::assertSame(['kind' => 'design', 'id' => $designId, 'name' => 'Héron'], Json::at(self::body($client), 'release', 'owner'));
    }

    public function testAnEventOfAnotherWorkspaceIsNotFound(): void
    {
        $client = self::signedInClient('Atelier');
        $client->jsonRequest('POST', '/api/events', ['name' => 'Salon', 'location' => 'Lyon', 'startDate' => '2099-06-10', 'endDate' => '2099-06-10']);
        $eventId = Json::string(self::body($client), 'id');

        $client->loginUser(SecurityUser::fromUser(self::createMember('Autre atelier')));
        $collectionId = self::collection($client);
        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['eventId' => $eventId, 'steps' => []]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTickingNeedsAKnownStepAndADoneFlag(): void
    {
        $client = self::signedInClient();
        $collectionId = self::collection($client);
        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => [['label' => 'Dessins', 'days' => 3]]]);
        $releaseId = Json::string(self::body($client), 'id');

        $client->jsonRequest('PUT', "/api/releases/$releaseId/steps", ['step' => 'Vernis', 'done' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('detail', self::body($client));

        $client->jsonRequest('PUT', "/api/releases/$releaseId/steps", ['done' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('violations', self::body($client));

        $client->jsonRequest('PUT', "/api/releases/$releaseId/steps", ['step' => 'Dessins']);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('violations', self::body($client));
    }

    public function testADesignInheritsItsCollectionRelease(): void
    {
        $client = self::signedInClient();
        $collectionId = self::collection($client);
        $client->jsonRequest('POST', '/api/designs', ['name' => 'Clairière', 'collectionId' => $collectionId]);
        $designId = Json::string(self::body($client), 'id');

        $client->jsonRequest('GET', "/api/designs/$designId/release");
        self::assertNull(self::body($client)['release']);

        $client->jsonRequest('POST', "/api/design-collections/$collectionId/release", ['day' => '2099-06-10', 'steps' => []]);

        $client->jsonRequest('GET', "/api/designs/$designId/release");
        self::assertTrue(self::body($client)['inherited']);
        self::assertSame('2099-06-10', Json::at(self::body($client), 'release', 'day'));
    }

    public function testThePlanningPageRenders(): void
    {
        $client = self::signedInClient();

        $client->request('GET', '/planning');

        self::assertResponseIsSuccessful();
    }

    private static function collection(KernelBrowser $client): string
    {
        $client->jsonRequest('POST', '/api/design-collections', ['name' => 'Sous-bois']);

        return Json::string(self::body($client), 'id');
    }

    /**
     * @return array<mixed>
     */
    private static function body(KernelBrowser $client): array
    {
        return Json::decode((string) $client->getResponse()->getContent());
    }
}
