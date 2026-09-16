<?php

declare(strict_types=1);

namespace App\Tree;

/**
 * Extra languages for tree questions. Source texts stay Dutch in the tree file.
 */
final class TreeQuestionTranslations
{
    /**
     * @var array<string, array<string, string>>
     */
    private const TEXTS = [
        'opening' => [
            'de' => 'Was ist in Ihrer Wohnung los?',
            'tr' => 'Evinizde neler oluyor?',
            'ja' => 'お住まいで何が起きていますか。',
        ],
        'ask_location' => [
            'de' => 'In welchem Raum befindet sich das Problem?',
            'tr' => 'Sorun hangi odada?',
            'ja' => '問題はどの部屋にありますか。',
        ],
        'ask_element' => [
            'de' => 'Welches Teil ist betroffen?',
            'tr' => 'Hangi parça ilgili?',
            'ja' => 'どの部分が関係していますか。',
        ],
        'ask_defect' => [
            'de' => 'Was genau sehen oder hören Sie?',
            'tr' => 'Tam olarak ne görüyor veya duyuyorsunuz?',
            'ja' => '何が見えますか、または聞こえますか。',
        ],
        'ask_cause' => [
            'de' => 'Wissen Sie, wodurch es kommt, oder ist die Ursache unbekannt?',
            'tr' => 'Nedenini biliyor musunuz, yoksa neden bilinmiyor mu?',
            'ja' => '原因はわかりますか。わからなければ不明でも構いません。',
        ],
        'ask_address' => [
            'de' => 'Wie lautet die Postleitzahl und die Hausnummer der Wohnung?',
            'tr' => 'Evin posta kodu ve kapı numarası nedir?',
            'ja' => '住まいの郵便番号と番地を教えてください。',
        ],
        'terminal_summary' => [
            'de' => 'Ich fasse das Problem zusammen, damit Sie es prüfen können.',
            'tr' => 'Kontrol etmeniz için sorunu özetliyorum.',
            'ja' => '確認できるように問題を要約します。',
        ],
        'terminal_review' => [
            'de' => 'Das können wir nicht automatisch abschließen. Es ist kein Mitarbeiter eingeschaltet; das ist eine Demo.',
            'tr' => 'Bunu otomatik tamamlayamayız. Personel yok; bu bir demodr.',
            'ja' => '自動では完了できません。担当者は呼ばれていません。これはデモです。',
        ],
        'terminal_out_of_scope' => [
            'de' => 'Das liegt außerhalb dessen, was diese Demo festhalten kann. Es ist kein Mitarbeiter eingeschaltet.',
            'tr' => 'Bu, bu demonun kaydedebileceğinin dışında. Personel yok.',
            'ja' => 'このデモで記録できる範囲外です。担当者は呼ばれていません。',
        ],
    ];

    public static function text(string $nodeId, string $language): ?string
    {
        $prefix = strtolower(substr($language, 0, 2));
        $text = self::TEXTS[$nodeId][$prefix] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}
