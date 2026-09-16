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
    public static function spoken(string $openingQuestion, string $language = 'nl-NL'): string
    {
        $opening = trim($openingQuestion);
        if ($opening === '') {
            $opening = 'Wat is er aan de hand in uw huurwoning?';
        }
        $hello = match (\App\Domain\UiLanguages::prefix($language)) {
            'en' => 'Hello, I will help you report a problem in your rental home. ',
            'de' => 'Hallo, ich helfe Ihnen, ein Problem in Ihrer Mietwohnung zu melden. ',
            'fr' => 'Bonjour, je vous aide à signaler un problème dans votre logement locatif. ',
            'es' => 'Hola, le ayudo a informar de un problema en su vivienda de alquiler. ',
            'tr' => 'Merhaba, kiralık evinizdeki bir sorunu bildirmenize yardımcı olurum. ',
            'ar' => 'مرحباً، سأساعدك في الإبلاغ عن مشكلة في مسكنك المستأجر. ',
            'pl' => 'Dzień dobry, pomogę zgłosić problem w Pana/Pani mieszkaniu na wynajem. ',
            'pap' => 'Bon dia, mi ta yuda bo raporta un problema den bo cas di hür. ',
            'zgh' => 'Azul, ad k-ɛawneɣ ad tmelḍ ugur deg taddart-nnek n ukru. ',
            'ja' => 'こんにちは。賃貸住宅の不具合の届出をお手伝いします。',
            default => 'Hallo, ik help u een probleem in uw huurwoning te melden. ',
        };

        return $hello.$opening;
    }

    public static function instructions(string $spoken, string $language = 'nl-NL'): string
    {
        $name = LiveFollowUpSpeech::spokenLanguageName($language);
        $greet = \App\Domain\UiLanguages::prefix($language) === 'nl'
            ? 'Greet immediately in Dutch without waiting for the resident. '
            : 'Greet immediately in '.$name.' without waiting for the resident. Do not greet in Dutch. ';

        return $greet
            .'Keep the same voice and a steady speaking speed; do not change voice, accent or pace. '
            .'Say this exactly, then pause and listen: '.$spoken
            .' After that greeting, follow the resident language. If they speak a clear sentence in another language, reply in that language immediately and stay there. '
            .'Do not switch back to Dutch after they change language. Loanwords such as okay do not count as a language switch. '
            .'If the backend asks whether to switch the app screens, say that question. Wait for yes or no before changing anything about the interface. '
            .'This is always a rental home. Never ask whether it is huur or koop.';
    }

    public static function commentary(string $spoken): string
    {
        return 'Begin the conversation now, following the instructions provided. '
            .'Say aloud to the resident: '.$spoken;
    }
}
