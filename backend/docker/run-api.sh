#!/bin/sh
set -eu
mkdir -p /app/var/log
exec php \
    -d display_errors=0 \
    -d output_buffering=0 \
    -d log_errors=1 \
    -d error_log=/proc/self/fd/2 \
    -S 0.0.0.0:8000 \
    -t /app/public \
    /app/docker/router.php
