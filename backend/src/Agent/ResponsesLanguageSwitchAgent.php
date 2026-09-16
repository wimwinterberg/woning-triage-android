<?php

declare(strict_types=1);

namespace App\Agent;

use App\Domain\LanguageSwitchTool;
use App\Http\OperationalLog;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Small Responses-API agent whose only tool is switch_language.
 * GPT-Live still uses client delegation; this agent runs on each delegated turn.
 */
final class ResponsesLanguageSwitchAgent implements LanguageSwitchAgent
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $apiKey = '',
        private readonly string $model = 'gpt-4.1-mini',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {
    }

    public function decide(string $text, string $conversationLanguage, ?string $uiLanguage): ?LanguageSwitchTool
    {
        if (($this->apiKey ?? '') === '') {
            return null;
        }
        $utterance = trim($text);
        if ($utterance === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/responses', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'instructions' => self::instructions(),
                    'input' => self::input($utterance, $conversationLanguage, $uiLanguage),
                    'tools' => [LanguageSwitchTool::schema()],
                    'tool_choice' => 'auto',
                    'parallel_tool_calls' => false,
                    'max_output_tokens' => 200,
                ],
                'timeout' => 6,
            ]);
            $payload = $response->toArray(false);
            if ($response->getStatusCode() >= 400) {
                OperationalLog::write(sprintf(
                    'language agent failed http_status=%d model=%s',
                    $response->getStatusCode(),
                    $this->model,
                ));

                return null;
            }
        } catch (\Throwable $exception) {
            OperationalLog::write('language agent failed exception='.$exception::class);

            return null;
        }

        $tool = LanguageSwitchTool::tryFromModelOutput($payload);
        OperationalLog::write(sprintf(
            'language agent model=%s tool=%s language=%s apply_ui=%s',
            $this->model,
            $tool instanceof LanguageSwitchTool ? LanguageSwitchTool::NAME : 'none',
            $tool instanceof LanguageSwitchTool ? $tool->language : '-',
            $tool instanceof LanguageSwitchTool ? ($tool->applyUi ? '1' : '0') : '-',
        ));

        return $tool;
    }

    public static function instructions(): string
    {
        return <<<'PROMPT'
You are the language-routing agent for Woningtriage. Your only capability is the switch_language function tool.
Call switch_language when the resident asks to speak another language, asks to change the app screens or interface, or clearly starts speaking a different language than the current conversation language.
Do not call the tool for ordinary intake answers in the current language, or for loanwords such as okay, ok, or oké.
apply_ui must be true only if they asked to change the screens, interface, or product language. apply_ui must be false if they only asked the assistant to speak that language, or they simply started speaking it.
Do not answer in text. Either call switch_language or return nothing.
PROMPT;
    }

    public static function input(string $utterance, string $conversationLanguage, ?string $uiLanguage): string
    {
        return "Current conversation language: {$conversationLanguage}\n"
            .'Current UI language: '.($uiLanguage ?? 'nl-NL')."\n"
            ."Resident utterance:\n".$utterance;
    }
}
