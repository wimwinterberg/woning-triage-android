<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * UI languages for the resident app. Conversation may follow GPT-Live in any
 * language; screens only switch after an explicit choice or a yes to the offer.
 *
 * List: Dutch plus the common home languages in the Netherlands (CBS-style),
 * and Japanese because GPT-Live already uses it in this app.
 */
final class UiLanguages
{
    public const DUTCH = 'nl-NL';
    public const ENGLISH = 'en-GB';

    /**
     * @return list<array{tag: string, native: string, name_nl: string}>
     */
    public static function catalog(): array
    {
        return [
            ['tag' => 'nl-NL', 'native' => 'Nederlands', 'name_nl' => 'Nederlands'],
            ['tag' => 'en-GB', 'native' => 'English', 'name_nl' => 'Engels'],
            ['tag' => 'de-DE', 'native' => 'Deutsch', 'name_nl' => 'Duits'],
            ['tag' => 'fr-FR', 'native' => 'Français', 'name_nl' => 'Frans'],
            ['tag' => 'es-ES', 'native' => 'Español', 'name_nl' => 'Spaans'],
            ['tag' => 'tr-TR', 'native' => 'Türkçe', 'name_nl' => 'Turks'],
            ['tag' => 'ar', 'native' => 'العربية', 'name_nl' => 'Arabisch'],
            ['tag' => 'pl-PL', 'native' => 'Polski', 'name_nl' => 'Pools'],
            ['tag' => 'pap', 'native' => 'Papiamentu', 'name_nl' => 'Papiaments'],
            ['tag' => 'zgh', 'native' => 'Tamazight', 'name_nl' => 'Berbers (Tamazight)'],
            ['tag' => 'ja-JP', 'native' => '日本語', 'name_nl' => 'Japans'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function tags(): array
    {
        return array_map(static fn (array $row): string => $row['tag'], self::catalog());
    }

    public static function normalize(?string $tag): ?string
    {
        if ($tag === null) {
            return null;
        }
        $tag = str_replace('_', '-', trim($tag));
        if ($tag === '') {
            return null;
        }
        $lower = strtolower($tag);
        foreach (self::catalog() as $row) {
            if (strtolower($row['tag']) === $lower) {
                return $row['tag'];
            }
            if (self::prefix($row['tag']) === self::prefix($tag)) {
                return $row['tag'];
            }
        }

        return null;
    }

    public static function isSupported(string $tag): bool
    {
        return self::normalize($tag) !== null;
    }

    /**
     * Screen language to offer when the resident speaks $conversationLanguage.
     */
    public static function uiTagForConversation(string $conversationLanguage): string
    {
        return self::normalize($conversationLanguage) ?? self::ENGLISH;
    }

    public static function prefix(string $tag): string
    {
        $tag = strtolower(str_replace('_', '-', $tag));
        if (str_starts_with($tag, 'pap')) {
            return 'pap';
        }
        if (str_starts_with($tag, 'zgh')) {
            return 'zgh';
        }

        return substr($tag, 0, 2);
    }

    /**
     * @param array{language?: string, reason?: string}|null $offer
     */
    public static function offerQuestion(?array $offer, string $spokenIn): string
    {
        $target = self::normalize((string) ($offer['language'] ?? '')) ?? self::ENGLISH;
        $unsupported = ($offer['reason'] ?? '') === 'unsupported';
        $name = self::nativeName($target);
        $prefix = self::prefix($spokenIn);

        if ($unsupported) {
            return match ($prefix) {
                'de' => 'Diese App gibt es nicht in Ihrer Sprache. Soll ich die Bildschirme auf Englisch umstellen?',
                'fr' => 'Cette application n’existe pas dans votre langue. Voulez-vous passer l’écran en anglais ?',
                'es' => 'Esta aplicación no está en su idioma. ¿Quiere pasar las pantallas a inglés?',
                'tr' => 'Uygulama bu dilde yok. Ekranları İngilizce yapmak ister misiniz?',
                'ar' => 'هذا التطبيق غير متوفر بهذه اللغة. هل تريد تحويل الشاشات إلى الإنجليزية؟',
                'pl' => 'Tej aplikacji nie ma w tym języku. Czy przełączyć ekrany na angielski?',
                'pap' => 'E aplikashon no ta den e idioma aki. Bo ke trese e pantayanan na ingles?',
                'zgh' => 'Asnas ad ur yelli s tutlayt-nnek. Tebɣiḍ ad nsenkel igdalln s Tenglizt?',
                'ja' => 'このアプリはその言語に対応していません。画面を英語に切り替えますか。',
                'nl' => 'Deze app is niet in die taal. Wilt u de schermen op Engels zetten?',
                default => 'This app is not available in that language. Should I switch the screens to English?',
            };
        }

        return match ($prefix) {
            'de' => 'Sie sprechen '.$name.'. Soll ich die App-Bildschirme auch auf '.$name.' umstellen?',
            'fr' => 'Vous parlez '.$name.'. Voulez-vous aussi passer l’application en '.$name.' ?',
            'es' => 'Está hablando '.$name.'. ¿Quiere cambiar también las pantallas a '.$name.'?',
            'tr' => $name.' konuşuyorsunuz. Uygulama ekranlarını da '.$name.' yapmak ister misiniz?',
            'ar' => 'أنت تتحدث '.$name.'. هل تريد أيضاً تغيير شاشات التطبيق إلى '.$name.'؟',
            'pl' => 'Mówi Pan/Pani po '.$name.'. Czy przełączyć też ekrany aplikacji na '.$name.'?',
            'pap' => 'Bo ta papia '.$name.'. Bo ke trese e pantayanan di e app tambe na '.$name.'?',
            'zgh' => 'Tessawalḍ '.$name.'. Tebɣiḍ ad nsenkel igdalln n usnas ɣer '.$name.'?',
            'ja' => $name.'で話されています。アプリの画面も'.$name.'に切り替えますか。',
            'nl' => 'U spreekt '.$name.'. Wilt u de app-schermen ook op '.$name.' zetten?',
            default => 'You are speaking '.$name.'. Should I switch the app screens to '.$name.' as well?',
        };
    }

    public static function nativeName(string $tag): string
    {
        $normalized = self::normalize($tag) ?? $tag;
        foreach (self::catalog() as $row) {
            if ($row['tag'] === $normalized) {
                return $row['native'];
            }
        }

        return $normalized;
    }
}
