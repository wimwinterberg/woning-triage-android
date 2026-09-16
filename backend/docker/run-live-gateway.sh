#!/bin/sh
set -eu
exec php -d output_buffering=0 bin/console woningtriage:live-gateway
