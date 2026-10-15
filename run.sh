#!/bin/sh
# Try Pendant on this computer: http://127.0.0.1:8090 (the built-in PHP server; for real use, see README "Serving it").
cd "$(dirname "$0")" && exec php -S 127.0.0.1:8090 -t web
