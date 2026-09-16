<?php

declare(strict_types=1);

/**
 * php -S router. Send the JSON body only after terminate() so listeners cannot
 * append a second payload or throw after headers are already sent.
 */
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

if (is_file(dirname(__DIR__).'/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = dirname(__DIR__).'/public'.$uri;
if ($uri !== '/' && is_file($publicFile)) {
    return false;
}

$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? '0', FILTER_VALIDATE_BOOLEAN);

$kernel = new Kernel($env, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);

ob_start();
try {
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    if (!str_contains(strtolower($message), 'headers already sent')) {
        error_log('[router] terminate '.$exception::class.': '.$message);
    }
}
$extra = ob_get_clean();
if (is_string($extra) && $extra !== '') {
    fwrite(STDERR, '[router] discarded extra output bytes='.\strlen($extra)."\n");
}

$content = (string) $response->getContent();
$response->headers->set('Content-Length', (string) \strlen($content));
$response->sendHeaders();
echo $content;
flush();
