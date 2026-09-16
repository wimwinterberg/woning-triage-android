<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Intake;

/**
 * Application-owned function tool. GPT-Live client delegation has no named
 * tools; the backend language agent calls this, then we execute it.
 */
final class LanguageSwitchTool
{
    public const NAME = 'switch_language';

    public function __construct(
        public readonly string $language,
        public readonly bool $applyUi,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $tags = UiLanguages::tags();

        return [
            'type' => 'function',
            'name' => self::NAME,
            'description' => 'Change the intake conversation language and optionally the app UI language. Call this when the resident asks to speak another language, asks to change the screens/interface, or clearly starts speaking a different language. Do not call it for ordinary answers in the current language, or for loanwords such as okay.',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'language' => [
                        'type' => 'string',
                        'enum' => $tags,
                        'description' => 'BCP-47 tag for the language the resident wants to use.',
                    ],
                    'apply_ui' => [
                        'type' => 'boolean',
                        'description' => 'True when they asked to change the app screens, interface, or product language. False when they only asked the assistant to speak that language, or they simply started speaking it.',
                    ],
                ],
                'required' => ['language', 'apply_ui'],
                'additionalProperties' => false,
            ],
        ];
    }

    public static function tryFromCall(string $name, mixed $arguments): ?self
    {
        if ($name !== self::NAME) {
            return null;
        }
        $parsed = self::argumentsArray($arguments);
        if ($parsed === null) {
            return null;
        }
        $language = UiLanguages::normalize(isset($parsed['language']) && is_string($parsed['language']) ? $parsed['language'] : null);
        if ($language === null) {
            return null;
        }

        return new self($language, self::boolValue($parsed['apply_ui'] ?? false));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function tryFromModelOutput(array $payload): ?self
    {
        foreach (self::functionCalls($payload) as $call) {
            $name = $call['name'] ?? '';
            $tool = self::tryFromCall(is_string($name) ? $name : '', $call['arguments'] ?? null);
            if ($tool instanceof self) {
                return $tool;
            }
        }

        return null;
    }

    public function apply(Intake $intake, IntakeDocument $document): void
    {
        $intake->setConversationLanguage($this->language);
        $document->invalidateSummary();
        if ($this->applyUi) {
            $document->uiLanguage = UiLanguages::uiTagForConversation($this->language);
            $document->uiLanguageOffer = null;
            $applied = $document->uiLanguage;
            $document->uiLanguageDeclined = array_values(array_filter(
                $document->uiLanguageDeclined,
                static fn (string $tag): bool => strcasecmp($tag, (string) $applied) !== 0,
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{name?: mixed, arguments?: mixed}>
     */
    private static function functionCalls(array $payload): array
    {
        $calls = [];
        $output = $payload['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = (string) ($item['type'] ?? '');
                if ($type === 'function_call' || $type === 'tool_call') {
                    $calls[] = [
                        'name' => $item['name'] ?? $item['function']['name'] ?? null,
                        'arguments' => $item['arguments'] ?? $item['function']['arguments'] ?? null,
                    ];
                }
            }
        }
        $choices = $payload['choices'] ?? null;
        if (is_array($choices)) {
            foreach ($choices as $choice) {
                $toolCalls = is_array($choice) ? ($choice['message']['tool_calls'] ?? null) : null;
                if (!is_array($toolCalls)) {
                    continue;
                }
                foreach ($toolCalls as $call) {
                    if (!is_array($call)) {
                        continue;
                    }
                    $calls[] = [
                        'name' => $call['function']['name'] ?? $call['name'] ?? null,
                        'arguments' => $call['function']['arguments'] ?? $call['arguments'] ?? null,
                    ];
                }
            }
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function argumentsArray(mixed $arguments): ?array
    {
        if (is_array($arguments)) {
            return $arguments;
        }
        if (!is_string($arguments) || trim($arguments) === '') {
            return null;
        }
        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return (bool) $value;
    }
}
