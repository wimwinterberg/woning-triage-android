<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Chooses the HTTP GPT-Live client when OPENAI_API_KEY is set, otherwise the fake.
 * Docker/dev must not force the fake: otherwise the phone gets a stub SDP and the
 * live-gateway logs "Skipping … because GPT-Live is not configured."
 */
final class GptLiveClientFactory
{
    public static function create(HttpGptLiveClient $http, FakeGptLiveClient $fake, ?string $apiKey): GptLiveClient
    {
        return ($apiKey ?? '') === '' ? $fake : $http;
    }
}
