#!/bin/bash
# Start php-fpm and Caddy; stop the container if either one dies, instead of
# serving 502s forever (upstream start.sh never notices a php-fpm failure).
SOCK=/run/php/php8.1-fpm.sock

/usr/sbin/php-fpm8.1 -F &
PHP_PID=$!

for _ in $(seq 1 30); do
  [ -S "$SOCK" ] && break
  if ! kill -0 "$PHP_PID" 2>/dev/null; then echo "php-fpm exited during startup" >&2; exit 1; fi
  sleep 1
done
[ -S "$SOCK" ] || { echo "php-fpm socket $SOCK never appeared" >&2; exit 1; }

/usr/bin/caddy run --config /etc/caddy/Caddyfile --adapter caddyfile &
CADDY_PID=$!

trap 'kill "$PHP_PID" "$CADDY_PID" 2>/dev/null' SIGTERM SIGQUIT SIGINT
wait -n
STATUS=$?
echo "a service exited (status $STATUS); stopping container" >&2
kill "$PHP_PID" "$CADDY_PID" 2>/dev/null
exit "$STATUS"
