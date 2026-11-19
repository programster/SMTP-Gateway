#!/bin/bash
# Puts the SMTP server's TLS certificate and key where it reads them from (/run/smtp-tls), owned by root and
# readable by www-data (which the server drops to after opening its ports), but nobody else.
#
#  - The certificate is SMTP_TLS_CERT_FILE and its key SMTP_TLS_KEY_FILE, which default to fullchain.pem and
#    privkey.pem (the names Let's Encrypt and certbot use), so files with those names in the ./tls folder are
#    used without setting anything. A name (or any path not starting with "/") is a file in the ./tls folder
#    (mounted at /tls), with or without "./tls/" in front, while a full path is used as it is.
#    They're copied if they've changed. This runs hourly from cron too, so a renewed certificate is picked up. The
#    server reads the files each time a connection starts TLS, so no restart is needed.
#  - If neither file is there (and neither setting is set), then in development (ENVIRONMENT=dev) only, a
#    self-signed certificate is generated (once). Connections are still encrypted, but clients have to be told not
#    to verify the certificate. In any other environment, it stops with an error instead, so production never runs
#    with a certificate clients can't verify.

set -e

# When run from cron, the container's environment isn't there, so read it from the .env file.
if [ -z "$ENVIRONMENT" ] && [ -f /.env ]; then
    set -a
    . /.env
    set +a
fi

TLS_DIR=/run/smtp-tls
mkdir -p "$TLS_DIR"
chown root:www-data "$TLS_DIR"
chmod 750 "$TLS_DIR"

install_file() {
    local source="$1"
    local destination="$2"

    if [ ! -r "$source" ]; then
        echo "ERROR: The SMTP TLS file $source doesn't exist, or can't be read." >&2
        exit 1
    fi

    if ! cmp --silent "$source" "$destination"; then
        install --owner=root --group=www-data --mode=640 "$source" "$destination"
        echo "Installed $source as $destination"
    fi
}

# Get the path inside the container of a TLS file setting. A full path (starting with "/") is used as it is.
# Anything else is in the mounted ./tls folder: a name, as most people give, or a path from that folder, with or
# without "./tls/" (or "tls/") in front, as the folder is written on the host.
resolve_tls_path() {
    local value="$1"

    if [[ "$value" == /* ]]; then
        echo "$value"
    else
        value="${value#./}"
        echo "/tls/${value#tls/}"
    fi
}

CERT_FILE="$(resolve_tls_path "${SMTP_TLS_CERT_FILE:-fullchain.pem}")"
KEY_FILE="$(resolve_tls_path "${SMTP_TLS_KEY_FILE:-privkey.pem}")"

if { [ -n "$SMTP_TLS_CERT_FILE" ] && [ -z "$SMTP_TLS_KEY_FILE" ]; } || { [ -z "$SMTP_TLS_CERT_FILE" ] && [ -n "$SMTP_TLS_KEY_FILE" ]; }; then
    echo "ERROR: SMTP_TLS_CERT_FILE and SMTP_TLS_KEY_FILE must be set together (or neither, to use" >&2
    echo "ERROR: ./tls/fullchain.pem and ./tls/privkey.pem)." >&2
    exit 1
elif [ -n "$SMTP_TLS_CERT_FILE" ] || [ -e "$CERT_FILE" ] || [ -e "$KEY_FILE" ]; then
    # Both are needed: install_file stops with an error naming whichever is missing.
    install_file "$CERT_FILE" "$TLS_DIR/cert.pem"
    install_file "$KEY_FILE" "$TLS_DIR/key.pem"
elif [ "$ENVIRONMENT" != "dev" ]; then
    echo "ERROR: The SMTP server needs a TLS certificate, as it only accepts encrypted connections. Put the" >&2
    echo "ERROR: certificate (with its chain) in ./tls/fullchain.pem and its private key in ./tls/privkey.pem, or" >&2
    echo "ERROR: set SMTP_TLS_CERT_FILE and SMTP_TLS_KEY_FILE to their names in ./tls (or full paths inside the" >&2
    echo "ERROR: container)." >&2
    echo "ERROR: (A self-signed certificate is only generated when ENVIRONMENT=dev.)" >&2
    exit 1
elif [ ! -f "$TLS_DIR/cert.pem" ] || [ ! -f "$TLS_DIR/key.pem" ]; then
    COMMON_NAME="${SMTP_SERVER_HOSTNAME:-$(hostname)}"
    echo "WARNING: There's no ./tls/fullchain.pem and ./tls/privkey.pem, so a self-signed certificate is being generated for $COMMON_NAME."
    echo "WARNING: Connections are encrypted, but clients can't verify the server. Set up a real certificate."

    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
        -subj "/CN=$COMMON_NAME" \
        -addext "subjectAltName=DNS:$COMMON_NAME" \
        -keyout "$TLS_DIR/key.pem" -out "$TLS_DIR/cert.pem" 2>/dev/null

    chown root:www-data "$TLS_DIR/cert.pem" "$TLS_DIR/key.pem"
    chmod 640 "$TLS_DIR/cert.pem" "$TLS_DIR/key.pem"
fi
