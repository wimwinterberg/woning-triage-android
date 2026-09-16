<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Spoken opening for GPT-Live. Instructions alone at session create do not make
 * the model talk; a post-start append is required.
 *
 * @see https://developers.openai.com/api/docs/guides/live-conversations
 */
final class LiveGreeting
{
    public static function spoken(string $openingQuestion): string
    {
        $opening = trim($openingQuestion);
        if ($opening === '') {
            $opening = 'Wat is er aan de hand in uw huurwoning?';
        }

        return 'Hallo, ik help u een probleem in uw huurwoning te melden. '.$opening;
    }

    public static function instructions(string $spoken): string
    {
        return 'Speak Dutch. Greet immediately without waiting for the resident. '
            .'Say this exactly, then pause and listen: '.$spoken
            .' This is always a rental home. Never ask whether it is huur or koop.';
    }

    public static function commentary(string $spoken): string
    {
        return 'Begin the conversation now, following the instructions provided. '
            .'Say aloud to the resident: '.$spoken;
    }
}
