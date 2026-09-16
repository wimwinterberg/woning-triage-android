<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Dutch LEDO labels stay canonical in the dossier. The API may add a
 * display_value in the resident's conversation language.
 */
final class LedoTerms
{
    /**
     * @var array<string, array<string, string>>
     */
    private const TERMS = [
        'Keuken' => ['en' => 'Kitchen', 'de' => 'Küche', 'tr' => 'Mutfak', 'ja' => '台所', 'fr' => 'Cuisine', 'es' => 'Cocina', 'ar' => 'المطبخ', 'pl' => 'Kuchnia', 'pap' => 'Kushina', 'zgh' => 'Taxxamt'],
        'Badkamer' => ['en' => 'Bathroom', 'de' => 'Badezimmer', 'tr' => 'Banyo', 'ja' => '浴室', 'fr' => 'Salle de bain', 'es' => 'Baño', 'ar' => 'الحمام', 'pl' => 'Łazienka', 'pap' => 'Baño', 'zgh' => 'Taxxamt n waman'],
        'Toilet' => ['en' => 'Toilet', 'de' => 'Toilette', 'tr' => 'Tuvalet', 'ja' => 'トイレ', 'fr' => 'Toilettes', 'es' => 'Aseo', 'ar' => 'المرحاض', 'pl' => 'Toaleta', 'pap' => 'Toilet', 'zgh' => 'Amenḍi'],
        'Woonkamer' => ['en' => 'Living room', 'de' => 'Wohnzimmer', 'tr' => 'Oturma odası', 'ja' => '居間', 'fr' => 'Salon', 'es' => 'Salón', 'ar' => 'غرفة المعيشة', 'pl' => 'Salon', 'pap' => 'Sala', 'zgh' => 'Taxxamt n usgunfu'],
        'Slaapkamer' => ['en' => 'Bedroom', 'de' => 'Schlafzimmer', 'tr' => 'Yatak odası', 'ja' => '寝室', 'fr' => 'Chambre', 'es' => 'Dormitorio', 'ar' => 'غرفة النوم', 'pl' => 'Sypialnia', 'pap' => 'Kuarto di drumi', 'zgh' => 'Taxxamt n ugun'],
        'Gang' => ['en' => 'Hallway', 'de' => 'Flur', 'tr' => 'Koridor', 'ja' => '廊下'],
        'Zolder' => ['en' => 'Attic', 'de' => 'Dachboden', 'tr' => 'Tavan arası', 'ja' => '屋根裏'],
        'Kelder' => ['en' => 'Basement', 'de' => 'Keller', 'tr' => 'Bodrum', 'ja' => '地下室'],
        'Tuin' => ['en' => 'Garden', 'de' => 'Garten', 'tr' => 'Bahçe', 'ja' => '庭'],
        'Balkon' => ['en' => 'Balcony', 'de' => 'Balkon', 'tr' => 'Balkon', 'ja' => 'バルコニー'],
        'Meterkast' => ['en' => 'Meter cupboard', 'de' => 'Zählerschrank', 'tr' => 'Sayaç dolabı', 'ja' => 'メーターボックス'],
        'Berging' => ['en' => 'Storage', 'de' => 'Abstellraum', 'tr' => 'Depo', 'ja' => '物置'],
        'Kraan' => ['en' => 'Tap', 'de' => 'Hahn', 'tr' => 'Musluk', 'ja' => '蛇口'],
        'Radiator' => ['en' => 'Radiator', 'de' => 'Heizkörper', 'tr' => 'Radyatör', 'ja' => 'ラジエーター'],
        'Leiding' => ['en' => 'Pipe', 'de' => 'Leitung', 'tr' => 'Boru', 'ja' => '配管'],
        'Ramen' => ['en' => 'Window', 'de' => 'Fenster', 'tr' => 'Pencere', 'ja' => '窓'],
        'Deur' => ['en' => 'Door', 'de' => 'Tür', 'tr' => 'Kapı', 'ja' => 'ドア'],
        'Stopcontact' => ['en' => 'Socket', 'de' => 'Steckdose', 'tr' => 'Priz', 'ja' => 'コンセント'],
        'Lamp' => ['en' => 'Light', 'de' => 'Lampe', 'tr' => 'Lamba', 'ja' => '照明'],
        'Dak' => ['en' => 'Roof', 'de' => 'Dach', 'tr' => 'Çatı', 'ja' => '屋根'],
        'CV-ketel' => ['en' => 'Boiler', 'de' => 'Heizkessel', 'tr' => 'Kazan', 'ja' => 'ボイラー'],
        'Druppelt' => ['en' => 'Dripping', 'de' => 'Tropft', 'tr' => 'Damlıyor', 'ja' => '滴っている'],
        'Lekt' => ['en' => 'Leaking', 'de' => 'Undicht', 'tr' => 'Sızıyor', 'ja' => '漏れている'],
        'Werkt niet' => ['en' => 'Does not work', 'de' => 'Geht nicht', 'tr' => 'Çalışmıyor', 'ja' => '動かない'],
        'Verstopt' => ['en' => 'Blocked', 'de' => 'Verstopft', 'tr' => 'Tıkalı', 'ja' => '詰まっている'],
        'Maakt geluid' => ['en' => 'Makes noise', 'de' => 'Macht Geräusche', 'tr' => 'Ses yapıyor', 'ja' => '音がする'],
        'Stank' => ['en' => 'Smell', 'de' => 'Geruch', 'tr' => 'Koku', 'ja' => '臭い'],
        'Scheur' => ['en' => 'Crack', 'de' => 'Riss', 'tr' => 'Çatlak', 'ja' => 'ひび'],
        'Nat' => ['en' => 'Damp', 'de' => 'Feucht', 'tr' => 'Islak', 'ja' => '湿っている'],
    ];

    public static function display(?string $value, string $language): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $prefix = UiLanguages::prefix($language);
        if ($prefix === 'nl') {
            return $value;
        }
        foreach (self::TERMS as $dutch => $map) {
            if (strcasecmp($value, $dutch) === 0) {
                return $map[$prefix] ?? $value;
            }
            if (str_starts_with($value, $dutch.' ')) {
                $rest = substr($value, strlen($dutch));

                return ($map[$prefix] ?? $dutch).$rest;
            }
        }

        return $value;
    }
}
