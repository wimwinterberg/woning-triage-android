#!/bin/sh
set -eu

cd /app

if [ ! -f vendor/autoload.php ] || [ "${COMPOSER_INSTALL:-0}" = "1" ]; then
    if [ "${APP_ENV:-dev}" = "prod" ]; then
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
    else
        composer install --no-interaction --prefer-dist
    fi
fi

# This script is invoked from the bind-mount (/app/docker/entrypoint.sh), not
# the image copy, so git pull + recreate is enough (no --build required).
cache_env="${APP_ENV:-dev}"
echo "WONINGTRIAGE_ENTRYPOINT=3 APP_ENV=${cache_env} GPS_LOOKUP=array"
echo "Resetting Symfony cache (${cache_env})..."
rm -rf var/cache/dev var/cache/prod var/cache/test
mkdir -p var/cache var/log var/share

wait_for_database() {
    php -r '
        $url = getenv("DATABASE_URL") ?: "";
        if (!preg_match("#^postgres(?:ql)?://([^:]+):([^@]+)@([^:/]+):(\d+)/([^?]+)#", $url, $m)) {
            fwrite(STDERR, "DATABASE_URL is missing or invalid.\n");
            exit(1);
        }
        [, $user, $password, $host, $port, $dbname] = $m;
        $user = rawurldecode($user);
        $password = rawurldecode($password);
        $dbname = rawurldecode($dbname);
        for ($i = 0; $i < 60; $i++) {
            try {
                new PDO(
                    sprintf("pgsql:host=%s;port=%s;dbname=%s", $host, $port, $dbname),
                    $user,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
                );
                exit(0);
            } catch (Throwable $exception) {
                if ($i === 0 || $i % 10 === 9) {
                    fwrite(STDERR, $exception->getMessage()."\n");
                }
                sleep(1);
            }
        }
        fwrite(STDERR, "Timed out waiting for PostgreSQL.\n");
        exit(1);
    '
}

if [ "${SKIP_DB_WAIT:-0}" != "1" ]; then
    echo "Waiting for PostgreSQL..."
    wait_for_database
fi

php bin/console cache:warmup --no-interaction

if [ "${SKIP_RELEASE:-0}" != "1" ]; then
    php bin/console doctrine:database:create --if-not-exists --no-interaction || true
    php bin/console woningtriage:release --no-interaction
    echo "Create a pilot user with: docker compose exec api php bin/console woningtriage:create-user --label=pilot"
fi

exec "$@"
