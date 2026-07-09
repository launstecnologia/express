#!/usr/bin/env bash
# Emite certificado Let's Encrypt e finaliza config HTTPS de um domínio de marketplace.
# Uso (no host, na raiz do projeto):
#   bash scripts/provision-tenant-ssl.sh julio.com.br

set -euo pipefail

DOMAIN="${1:?Informe o domínio, ex: julio.com.br}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE="docker compose"
APP_SERVICE="app"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-express}"
EMAIL="${TENANT_CERTBOT_EMAIL:-${CERTBOT_EMAIL:-admin@express.app.br}}"

echo "[ssl] Recarregando nginx (config HTTP)..."
$COMPOSE exec -T nginx nginx -s reload

echo "[ssl] Emitindo certificado Let's Encrypt para ${DOMAIN}..."
$COMPOSE run --rm certbot certonly \
  --webroot -w /var/www/certbot \
  -d "$DOMAIN" \
  --email "$EMAIL" \
  --agree-tos \
  --non-interactive \
  --keep-until-expiring

echo "[ssl] Gerando configuração HTTPS..."
if ! $COMPOSE ps --status running "$APP_SERVICE" 2>/dev/null | grep -q "$APP_SERVICE"; then
    echo "[ssl] ERRO: container '$APP_SERVICE' não está rodando. Rode: docker compose up -d app" >&2
    exit 1
fi
$COMPOSE exec -T "$APP_SERVICE" php artisan tenant:ssl-finalize "$DOMAIN"

echo "[ssl] Recarregando nginx (HTTPS)..."
$COMPOSE exec -T nginx nginx -s reload

echo "[ssl] Concluído: https://${DOMAIN}"
