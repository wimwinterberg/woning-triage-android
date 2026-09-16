<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Live\FakeGptLiveClient;
use App\Live\GptLiveClientFactory;
use App\Live\HttpGptLiveClient;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GptLiveClientFactoryTest extends TestCase
{
    public function testUsesHttpClientWhenApiKeyIsSet(): void
    {
        $http = new HttpGptLiveClient($this->createStub(HttpClientInterface::class), 'sk-test');
        $fake = new FakeGptLiveClient();
        self::assertSame($http, GptLiveClientFactory::create($http, $fake, 'sk-test'));
    }

    public function testUsesFakeWhenApiKeyIsMissing(): void
    {
        $http = new HttpGptLiveClient($this->createStub(HttpClientInterface::class), '');
        $fake = new FakeGptLiveClient();
        self::assertSame($fake, GptLiveClientFactory::create($http, $fake, ''));
        self::assertSame($fake, GptLiveClientFactory::create($http, $fake, null));
    }
}
