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
        $pace = 'Keep the same voice and a steady speaking speed. Do not change voice, accent or pace.';
        $stay = 'Reply in the language the resident is using. If they switched away from Dutch, stay in that language and do not switch back to Dutch.';
        if (str_starts_with($language, 'nl')) {
            $ask = $sameQuestion
                ? 'The previous answer was not stored. Do not repeat the question word for word. Ask the same meaning in one different short sentence: '.$question
                : 'Say this next question aloud: '.$question;

            return $pace.' '.$stay.' '.$ask.' If the last resident utterance was clearly English, German, Turkish or Japanese, say the question in that language instead of Dutch.';
        }

        $name = self::spokenLanguageName($language);
        $lock = 'Speak only '.$name.' now. Do not speak Dutch until the resident clearly speaks Dutch again.';
        if ($sameQuestion) {
            return $pace.' '.$lock.' The previous answer was not stored. Ask the same meaning in one different short '.$name.' sentence. Meaning: '.$question;
        }
        if (self::hasNativeTreeText($language)) {
            return $pace.' '.$lock.' Say this aloud: '.$question;
        }

        return $pace.' '.$lock.' Translate this meaning into natural '.$name.' and say it. Do not read it in Dutch: '.$question;
    }

    public static function afterAddressVerified(string $thankYou, string $nextQuestion, string $language): string
    {
        $pace = 'Keep the same voice and a steady speaking speed. Do not change voice, accent or pace.';
        $name = self::spokenLanguageName($language);
        $lock = str_starts_with($language, 'nl')
            ? 'Reply in the language the resident is using.'
            : 'Speak only '.$name.' now. Do not speak Dutch.';

        return $pace.' '.$lock.' First say this thank-you exactly, do not skip it: '.$thankYou
            .' Then ask this next question: '.$nextQuestion;
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

    public static function idlePrompt(string $language): string
    {
        $spoken = match (true) {
            str_starts_with($language, 'de') => 'Sind Sie noch da? Ich warte auf Ihre Antwort.',
            str_starts_with($language, 'tr') => 'Hâlâ orada mısınız? Cevabınızı bekliyorum.',
            str_starts_with($language, 'ja') => 'まだいらっしゃいますか。返答をお待ちしています。',
            str_starts_with($language, 'en') => 'Are you still there? I am waiting for your answer.',
            default => 'Bent u er nog? Ik wacht op uw antwoord.',
        };

        return self::sayExactly($spoken, $language);
    }

    public static function idleClosing(string $language): string
    {
        $spoken = match (true) {
            str_starts_with($language, 'de') => 'Es bleibt still. Ich beende das Gespräch jetzt. Sie können später weitermachen.',
            str_starts_with($language, 'tr') => 'Sessiz kaldı. Görüşmeyi şimdi kapatıyorum. Daha sonra devam edebilirsiniz.',
            str_starts_with($language, 'ja') => '応答がないため、会話を終了します。後から続けられます。',
            str_starts_with($language, 'en') => 'It has gone quiet, so I am closing the conversation now. You can continue later.',
            default => 'Het blijft stil, daarom sluit ik het gesprek nu. U kunt later verdergaan.',
        };

        return self::sayExactly($spoken, $language);
    }

    private static function sayExactly(string $spoken, string $language): string
    {
        $pace = 'Keep the same voice and a steady speaking speed. Do not change voice, accent or pace.';
        $lock = str_starts_with($language, 'nl')
            ? 'Reply in the language the resident is using.'
            : 'Speak only '.self::spokenLanguageName($language).' now. Do not speak Dutch.';

        return $pace.' '.$lock.' Say this exactly, then pause and listen: '.$spoken;
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
        $prefix = strtolower(substr($language, 0, 2));

        return in_array($prefix, ['nl', 'en', 'de', 'tr', 'ja'], true);
    }
}
