<?php

declare(strict_types=1);

namespace App\Live;

/**
 * Sideband commentary after a delegation. Must never force Dutch after the
 * resident has started speaking another language.
 */
final class LiveFollowUpSpeech
{
    public static function commentary(string $question, string $language, bool $sameQuestion): string
    {
        $stay = 'Reply in the language the resident is using. If they switched away from Dutch, stay in that language and do not switch back to Dutch.';
        if (str_starts_with($language, 'nl')) {
            $ask = $sameQuestion
                ? 'The previous answer was not stored. Do not repeat the question word for word. Ask the same meaning in one different short sentence: '.$question
                : 'Say this next question aloud: '.$question;

            return $stay.' '.$ask.' If the last resident utterance was clearly English, German, Turkish or Japanese, say the question in that language instead of Dutch.';
        }

        $name = self::spokenLanguageName($language);
        $lock = 'Speak only '.$name.' now. Do not speak Dutch until the resident clearly speaks Dutch again.';
        if ($sameQuestion) {
            return $lock.' The previous answer was not stored. Ask the same meaning in one different short '.$name.' sentence. Meaning: '.$question;
        }
        if (self::hasNativeTreeText($language)) {
            return $lock.' Say this aloud: '.$question;
        }

        return $lock.' Translate this meaning into natural '.$name.' and say it. Do not read it in Dutch: '.$question;
    }

    public static function switchInstructions(string $language): string
    {
        $name = self::spokenLanguageName($language);

        return 'The resident is speaking '.$name.'. From now on speak only '.$name.'. Do not greet again. Do not switch back to Dutch unless they clearly speak Dutch again.';
    }

    public static function waitingOnBackend(): string
    {
        return 'The backend is updating the dossier. Wait for the result before asking further.';
    }

    public static function noTranscript(): string
    {
        return 'There is no new resident answer yet. Do not ask a new question; wait until the resident speaks.';
    }

    public static function spokenLanguageName(string $language): string
    {
        return match (true) {
            str_starts_with($language, 'en') => 'English',
            str_starts_with($language, 'de') => 'German',
            str_starts_with($language, 'tr') => 'Turkish',
            str_starts_with($language, 'ja') => 'Japanese',
            str_starts_with($language, 'nl') => 'Dutch',
            default => $language,
        };
    }

    public static function hasNativeTreeText(string $language): bool
    {
        return str_starts_with($language, 'nl') || str_starts_with($language, 'en');
    }
}
