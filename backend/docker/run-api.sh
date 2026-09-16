#!/bin/sh
set -eu
exec php -d display_errors=0 -d output_buffering=0 -S 0.0.0.0:8000 -t /app/public /app/docker/router.php
