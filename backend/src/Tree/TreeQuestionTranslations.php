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
            'fr' => 'Que se passe-t-il dans votre logement ?',
            'es' => '¿Qué ocurre en su vivienda?',
            'ar' => 'ماذا يحدث في مسكنك؟',
            'pl' => 'Co się dzieje w Pana/Pani mieszkaniu?',
            'pap' => 'Kiko ta pasando den bo cas?',
            'zgh' => 'D acu iḍerrun deg taddart-nnek?',
        ],
        'ask_location' => [
            'de' => 'In welchem Raum befindet sich das Problem?',
            'tr' => 'Sorun hangi odada?',
            'ja' => '問題はどの部屋にありますか。',
            'fr' => 'Dans quelle pièce se trouve le problème ?',
            'es' => '¿En qué habitación está el problema?',
            'ar' => 'في أي غرفة توجد المشكلة؟',
            'pl' => 'W którym pomieszczeniu jest problem?',
            'pap' => 'Den ki kuarto e problema ta?',
            'zgh' => 'Anwa taxxamt ay deg llan ugur?',
        ],
        'ask_element' => [
            'de' => 'Welches Teil ist betroffen?',
            'tr' => 'Hangi parça ilgili?',
            'ja' => 'どの部分が関係していますか。',
            'fr' => 'Quelle partie est concernée ?',
            'es' => '¿Qué parte está afectada?',
            'ar' => 'أي جزء معني؟',
            'pl' => 'Która część jest uszkodzona?',
            'pap' => 'Ki parti ta afektá?',
            'zgh' => 'Anwa aḥric ay iɛnan?',
        ],
        'ask_defect' => [
            'de' => 'Was genau sehen oder hören Sie?',
            'tr' => 'Tam olarak ne görüyor veya duyuyorsunuz?',
            'ja' => '何が見えますか、または聞こえますか。',
            'fr' => 'Que voyez-vous ou entendez-vous exactement ?',
            'es' => '¿Qué ve o escucha exactamente?',
            'ar' => 'ماذا ترى أو تسمع بالضبط؟',
            'pl' => 'Co dokładnie Pan/Pani widzi lub słyszy?',
            'pap' => 'Kiko bo ta mirá òf tende eksaktamente?',
            'zgh' => 'D acu ay tettwaliḍ neɣ tesleḍ s tidet?',
        ],
        'ask_cause' => [
            'de' => 'Wissen Sie, wodurch es kommt, oder ist die Ursache unbekannt?',
            'tr' => 'Nedenini biliyor musunuz, yoksa neden bilinmiyor mu?',
            'ja' => '原因はわかりますか。わからなければ不明でも構いません。',
            'fr' => 'Savez-vous d’où cela vient, ou la cause est-elle inconnue ?',
            'es' => '¿Sabe por qué ocurre, o la causa es desconocida?',
            'ar' => 'هل تعرف السبب أم أنه غير معروف؟',
            'pl' => 'Czy zna Pan/Pani przyczynę, czy jest nieznana?',
            'pap' => 'Bo sa di kiko e ta bini, òf e kousa no ta konosí?',
            'zgh' => 'Tssneḍ s wacu i d-yekka, neɣ ur yettwassen ara?',
        ],
        'ask_address' => [
            'de' => 'Wie lautet die Postleitzahl und die Hausnummer der Wohnung?',
            'tr' => 'Evin posta kodu ve kapı numarası nedir?',
            'ja' => '住まいの郵便番号と番地を教えてください。',
            'fr' => 'Quel est le code postal et le numéro de la maison ?',
            'es' => '¿Cuál es el código postal y el número de la vivienda?',
            'ar' => 'ما هو الرمز البريدي ورقم المنزل؟',
            'pl' => 'Jaki jest kod pocztowy i numer domu?',
            'pap' => 'Kiko ta e kódigo postal i number di cas?',
            'zgh' => 'D acu-t unḍil n lpusṭa d uṭṭun n taddart?',
        ],
        'terminal_summary' => [
            'de' => 'Ich fasse das Problem zusammen, damit Sie es prüfen können.',
            'tr' => 'Kontrol etmeniz için sorunu özetliyorum.',
            'ja' => '確認できるように問題を要約します。',
            'fr' => 'Je résume le problème pour que vous puissiez le vérifier.',
            'es' => 'Resumo el problema para que pueda comprobarlo.',
            'ar' => 'س ألخص المشكلة حتى تتمكن من مراجعتها.',
            'pl' => 'Podsumuję problem, żeby można było go sprawdzić.',
            'pap' => 'Mi ta resume e problema pa bo por kontrolá.',
            'zgh' => 'Ad ngezzem ugur iwakken ad tsenqedḍ.',
        ],
        'terminal_review' => [
            'de' => 'Das können wir nicht automatisch abschließen. Es ist kein Mitarbeiter eingeschaltet; das ist eine Demo.',
            'tr' => 'Bunu otomatik tamamlayamayız. Personel yok; bu bir demodr.',
            'ja' => '自動では完了できません。担当者は呼ばれていません。これはデモです。',
            'fr' => 'Nous ne pouvons pas terminer automatiquement. Aucun collaborateur n’est appelé ; c’est une démo.',
            'es' => 'No podemos cerrarlo automáticamente. No hay personal; es una demostración.',
            'ar' => 'لا يمكن إكمال ذلك تلقائياً. لا يوجد موظف؛ هذه نسخة تجريبية.',
            'pl' => 'Nie możemy tego zakończyć automatycznie. Nie wezwano pracownika; to demo.',
            'pap' => 'Nos no por terminá esaki outomátikamente. No tin personal; esaki ta un demo.',
            'zgh' => 'Ur nezmir ad nkemmel s wudem awurman. Ulac amaraw; d tarmit.',
        ],
        'terminal_out_of_scope' => [
            'de' => 'Das liegt außerhalb dessen, was diese Demo festhalten kann. Es ist kein Mitarbeiter eingeschaltet.',
            'tr' => 'Bu, bu demonun kaydedebileceğinin dışında. Personel yok.',
            'ja' => 'このデモで記録できる範囲外です。担当者は呼ばれていません。',
            'fr' => 'Cela dépasse ce que cette démo peut enregistrer. Aucun collaborateur n’est appelé.',
            'es' => 'Esto queda fuera de lo que esta demostración puede registrar. No hay personal.',
            'ar' => 'هذا خارج ما يمكن لهذه النسخة التجريبية تسجيله. لا يوجد موظف.',
            'pl' => 'To wykracza poza to, co to demo może zapisać. Nie wezwano pracownika.',
            'pap' => 'Esaki ta pafo di loke e demo por registrá. No tin personal.',
            'zgh' => 'Ayagi yeffeɣ ayen i izemren tarmit-a ad tsekles. Ulac amaraw.',
        ],
    ];

    public static function text(string $nodeId, string $language): ?string
    {
        $prefix = \App\Domain\UiLanguages::prefix($language);
        $text = self::TEXTS[$nodeId][$prefix] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}
