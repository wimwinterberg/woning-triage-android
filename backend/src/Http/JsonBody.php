<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

final class JsonBody
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '' || $content === 'null' || $content === '{}' || $content === '[]') {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestException('Ongeldige JSON.');
        }
        if (!is_array($decoded)) {
            throw new BadRequestException('Ongeldige JSON.');
        }
        if ($decoded !== [] && array_is_list($decoded)) {
            throw new BadRequestException('Ongeldige JSON.');
        }

        return $decoded;
    }
}
