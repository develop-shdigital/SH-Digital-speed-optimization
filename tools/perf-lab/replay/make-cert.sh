#!/usr/bin/env bash
# Self-signed certificate for the replay server (browsers in the tests ignore certificate errors).
#   SITE=https://www.example.ch bash replay/make-cert.sh
set -euo pipefail
LAB="$(cd "$(dirname "$0")/.." && pwd)"
DIR="${REPLAY_DIR:-$LAB/work/replay}"
HOST="$(echo "${SITE:?Set SITE, for example SITE=https://www.example.ch}" | sed -E 's#^https?://##; s#/.*$##')"
mkdir -p "$DIR"
openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj "/CN=$HOST" -addext "subjectAltName=DNS:$HOST" \
	-keyout "$DIR/key.pem" -out "$DIR/cert.pem" 2>/dev/null
echo "certificate for $HOST in $DIR"
