<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Builds a stub SDP answer with the same m-line order as the offer.
 * A fake answer is never a live GPT-Live proof; it only avoids WebRTC m-line crashes.
 */
final class FakeSdp
{
    public static function answerFromOffer(string $offer): string
    {
        /** @var list<array{kind: string, rest: string, mid: string}> $sections */
        $sections = [];
        foreach (preg_split("/\R/", $offer) ?: [] as $line) {
            if (str_starts_with($line, 'm=')) {
                $parts = explode(' ', $line, 3);
                $sections[] = [
                    'kind' => substr($parts[0], 2),
                    'rest' => $parts[2] ?? '',
                    'mid' => (string) count($sections),
                ];
            } elseif (str_starts_with($line, 'a=mid:') && $sections !== []) {
                $sections[array_key_last($sections)]['mid'] = trim(substr($line, 6));
            }
        }

        $lines = [
            'v=0',
            'o=- 0 0 IN IP4 127.0.0.1',
            's=WoningtriageFake',
            't=0 0',
        ];
        foreach ($sections as $section) {
            $lines[] = 'm='.$section['kind'].' 9 '.$section['rest'];
            $lines[] = 'c=IN IP4 0.0.0.0';
            $lines[] = 'a=mid:'.$section['mid'];
            $lines[] = 'a=inactive';
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
