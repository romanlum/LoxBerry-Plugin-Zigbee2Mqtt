#!/bin/bash
#
# Resolves a TLS certificate/key pair for the wsproxy daemon to use, so
# browsers can reach it over wss:// (plain ws:// from an HTTPS LoxBerry page
# is blocked as mixed content).
#
# Preferred: reuse LoxBerry's own certificate, so the browser already trusts
# it (same hostname, just a different port) with no warnings. Candidate
# paths below cover the common LoxBerry/Let's Encrypt locations; NOTE these
# have not been verified against a real LoxBerry installation and may need
# adjusting for the actual system this plugin targets.
#
# Falls back to generating a self-signed certificate if none of the known
# paths exist. wss:// still avoids mixed-content blocking with a self-signed
# cert; the browser will just show a one-time trust warning for that port.
#
# Usage: resolve-cert.sh <destination-dir>
# Writes <destination-dir>/cert.pem and <destination-dir>/key.pem.

set -e

DESTDIR=$1
if [ -z "$DESTDIR" ]; then
    echo "<ERROR> resolve-cert.sh: destination directory required"
    exit 1
fi
mkdir -p "$DESTDIR"

CANDIDATES=(
    "/etc/ssl/loxberry/loxberry.crt:/etc/ssl/loxberry/loxberry.key"
    "/etc/loxberry/ssl/loxberry.crt:/etc/loxberry/ssl/loxberry.key"
)

# LoxBerry's general config may name an explicit certificate/key pair.
if [ -n "$LBHOMEDIR" ] && [ -f "$LBHOMEDIR/config/system/general.cfg" ]; then
    CERTFILE=$(grep -im1 '^CERTFILE=' "$LBHOMEDIR/config/system/general.cfg" | cut -d= -f2- | tr -d '\r')
    KEYFILE=$(grep -im1 '^KEYFILE=' "$LBHOMEDIR/config/system/general.cfg" | cut -d= -f2- | tr -d '\r')
    if [ -n "$CERTFILE" ] && [ -n "$KEYFILE" ]; then
        CANDIDATES=("$CERTFILE:$KEYFILE" "${CANDIDATES[@]}")
    fi
fi

# A Let's Encrypt certificate, if LoxBerry (or its Let's Encrypt plugin) is
# managing one.
for LE_DIR in /etc/letsencrypt/live/*/; do
    [ -d "$LE_DIR" ] || continue
    CANDIDATES+=("${LE_DIR}fullchain.pem:${LE_DIR}privkey.pem")
done

for PAIR in "${CANDIDATES[@]}"; do
    CERT="${PAIR%%:*}"
    KEY="${PAIR##*:}"
    if [ -f "$CERT" ] && [ -f "$KEY" ]; then
        echo "<INFO> Reusing existing certificate: $CERT"
        cp -f "$CERT" "$DESTDIR/cert.pem"
        cp -f "$KEY" "$DESTDIR/key.pem"
        exit 0
    fi
done

echo "<INFO> No existing certificate found, generating a self-signed one for wsproxy"
openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -subj "/CN=$(hostname -f 2>/dev/null || hostname)" \
    -keyout "$DESTDIR/key.pem" \
    -out "$DESTDIR/cert.pem" >/dev/null 2>&1

exit 0
