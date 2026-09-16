<?php

declare(strict_types=1);

namespace App\Agent;

final class LanguageSwitchAgentFactory
{
    public static function create(ResponsesLanguageSwitchAgent $http, FakeLanguageSwitchAgent $fake, ?string $apiKey): LanguageSwitchAgent
    {
        return ($apiKey ?? '') === '' ? $fake : $http;
    }
}
