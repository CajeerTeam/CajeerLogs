#!/bin/sh
# Executed by the official nginx entrypoint before nginx starts.
set -eu
case "${APP_URL:-}" in https://*) ;; *) echo 'Gateway requires HTTPS APP_URL' >&2; exit 1;; esac
authority=${APP_URL#https://}
authority=${authority%/}
# Limit generated nginx tokens to DNS/IPv4 or bracketed IPv6, optional numeric port.
if ! printf '%s\n' "$authority" | grep -Eq '^([A-Za-z0-9][A-Za-z0-9.-]*|\[[0-9A-Fa-f:.]+\])(:[0-9]{1,5})?$'; then
    echo 'Invalid gateway APP_URL authority' >&2; exit 1
fi
# Refuse multiple lines even if one line matched grep.
case "$authority" in *[!A-Za-z0-9.:[\]-]*) echo 'Invalid gateway APP_URL characters' >&2; exit 1;; esac
# Separate bracketed IPv6 from optional port.
case "$authority" in
    \[*\]*) host=${authority%%]*}]; suffix=${authority#*]}; port=${suffix#:} ;;
    *:*) host=${authority%:*}; port=${authority##*:} ;;
    *) host=$authority; port= ;;
esac
if [ -n "$port" ]; then
    if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then echo 'Invalid gateway port' >&2; exit 1; fi
fi
# Avoid accepting alternate port spelling that PHP will normalize differently.
case "$port" in 0*) echo 'Invalid gateway port' >&2; exit 1;; esac
out=${CAJEER_NGINX_ORIGIN_FILE:-/etc/nginx/conf.d/00-origin.conf}
# Only trusted process environment is used; values are constrained above.
{
    printf 'map $http_host $cajeer_public_host_ok {\n    default 0;\n'
    if [ -z "$port" ] || [ "$port" = 443 ]; then
        printf '    "%s" 1;\n    "%s:443" 1;\n' "$host" "$host"
    else
        printf '    "%s" 1;\n' "$authority"
    fi
    printf '}\n'
} > "$out"
