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

    public static function idlePromptSpoken(string $language): string
    {
        return match (true) {
            str_starts_with($language, 'de') => 'Sind Sie noch da? Ich warte auf Ihre Antwort.',
            str_starts_with($language, 'tr') => 'Hâlâ orada mısınız? Cevabınızı bekliyorum.',
            str_starts_with($language, 'ja') => 'まだいらっしゃいますか。返答をお待ちしています。',
            str_starts_with($language, 'en') => 'Are you still there? I am waiting for your answer.',
            default => 'Bent u er nog? Ik wacht op uw antwoord.',
        };
    }

    public static function idleClosingSpoken(string $language): string
    {
        return match (true) {
            str_starts_with($language, 'de') => 'Es bleibt still. Ich beende das Gespräch jetzt. Sie können später weitermachen.',
            str_starts_with($language, 'tr') => 'Sessiz kaldı. Görüşmeyi şimdi kapatıyorum. Daha sonra devam edebilirsiniz.',
            str_starts_with($language, 'ja') => '応答がないため、会話を終了します。後から続けられます。',
            str_starts_with($language, 'en') => 'It has gone quiet, so I am closing the conversation now. You can continue later.',
            default => 'Het blijft stil, daarom sluit ik het gesprek nu. U kunt later verdergaan.',
        };
    }

    public static function idlePrompt(string $language): string
    {
        return self::sayExactly(self::idlePromptSpoken($language), $language);
    }

    public static function idleClosing(string $language): string
    {
        return self::sayExactly(self::idleClosingSpoken($language), $language);
    }

    private static function sayExactly(string $spoken, string $language): string
    {
        $pace = 'Keep the same voice and a steady speaking speed. Do not change voice, accent or pace.';
        $lock = str_starts_with($language, 'nl')
            ? 'Reply in the language the resident is using.'
            : 'Speak only '.self::spokenLanguageName($language).' now. Do not speak Dutch.';

        return $pace.' '.$lock.' Say this exactly, then pause and listen: '.$spoken;
    }

    public static function uiLanguageOffer(string $question, string $language): string
    {
        return self::sayExactly($question, $language);
    }

    public static function afterUiLanguageSwitchSpoken(string $language): string
    {
        return match (true) {
            str_starts_with($language, 'de') => 'Die App-Bildschirme sind jetzt auf Deutsch. Sie können das später noch ändern.',
            str_starts_with($language, 'tr') => 'Uygulama ekranları artık Türkçe. Bunu daha sonra değiştirebilirsiniz.',
            str_starts_with($language, 'ja') => 'アプリの画面は日本語になりました。後から変更できます。',
            str_starts_with($language, 'en') => 'The app screens are now in English. You can change this later.',
            str_starts_with($language, 'fr') => 'Les écrans de l’application sont maintenant en français. Vous pourrez encore les modifier plus tard.',
            str_starts_with($language, 'es') => 'Las pantallas de la aplicación están ahora en español. Puede cambiarlo más adelante.',
            str_starts_with($language, 'pl') => 'Ekrany aplikacji są teraz po polsku. Można to później zmienić.',
            str_starts_with($language, 'ar') => 'أصبحت شاشات التطبيق الآن بالعربية. يمكنك تغيير ذلك لاحقاً.',
            default => 'De app-schermen staan nu op Nederlands. U kunt dit later nog wijzigen.',
        };
    }

    public static function afterUiLanguageSwitch(string $language): string
    {
        return self::sayExactly(self::afterUiLanguageSwitchSpoken($language), $language);
    }

    public static function spokenLanguageName(string $language): string
    {
        return match (true) {
            str_starts_with($language, 'pap') => 'Papiamentu',
            str_starts_with($language, 'zgh') => 'Tamazight',
            str_starts_with($language, 'en') => 'English',
            str_starts_with($language, 'de') => 'German',
            str_starts_with($language, 'tr') => 'Turkish',
            str_starts_with($language, 'ja') => 'Japanese',
            str_starts_with($language, 'nl') => 'Dutch',
            str_starts_with($language, 'fr') => 'French',
            str_starts_with($language, 'es') => 'Spanish',
            str_starts_with($language, 'ar') => 'Arabic',
            str_starts_with($language, 'pl') => 'Polish',
            default => $language,
        };
    }

    public static function hasNativeTreeText(string $language): bool
    {
        $prefix = \App\Domain\UiLanguages::prefix($language);

        return in_array($prefix, ['nl', 'en', 'de', 'tr', 'ja', 'fr', 'es', 'ar', 'pl', 'pap', 'zgh'], true);
    }
}
