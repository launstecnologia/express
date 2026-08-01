#!/usr/bin/env bash
# deploy.sh — Deploy completo no VPS
# Uso:
#   Primeiro deploy:  bash deploy.sh install
#   Atualização:      bash deploy.sh update
#   Só migrations:    bash deploy.sh migrate
#   Ver logs:         bash deploy.sh logs
#   Parar tudo:       bash deploy.sh down
#   Status:           bash deploy.sh status

set -euo pipefail

COMPOSE="docker compose"
APP_SERVICE="app"
APP_CONTAINER="express-app"
BLUE='\033[0;34m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${BLUE}[deploy]${NC} $1"; }
ok()   { echo -e "${GREEN}[✔]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err()  { echo -e "${RED}[✗]${NC} $1"; exit 1; }

# ----------------------------------------------------------------
# Verifica pré-requisitos
# ----------------------------------------------------------------
check_requirements() {
    command -v docker >/dev/null 2>&1 || err "Docker não instalado. Instale em https://docs.docker.com/engine/install/"
    docker compose version >/dev/null 2>&1 || err "Docker Compose v2 não encontrado."
    [ -f ".env" ] || err "Arquivo .env não encontrado. Copie .env.example e preencha."
    [ -f "automacao/.env" ] || err "Arquivo automacao/.env não encontrado. Copie automacao/.env.example e preencha."
}

# ----------------------------------------------------------------
# Verifica se as chaves API batem
# ----------------------------------------------------------------
check_api_keys() {
    LARAVEL_KEY=$(grep "^AUTOMACAO_API_KEY=" .env | cut -d'=' -f2-)
    PYTHON_KEY=$(grep "^AUTOMACAO_API_KEY=" automacao/.env | cut -d'=' -f2-)

    if [ -z "$LARAVEL_KEY" ] || [ -z "$PYTHON_KEY" ]; then
        warn "AUTOMACAO_API_KEY não configurada em um dos .env!"
        return
    fi

    if [ "$LARAVEL_KEY" != "$PYTHON_KEY" ]; then
        err "AUTOMACAO_API_KEY diferente entre .env e automacao/.env — as chaves devem ser iguais!"
    fi
    ok "Chaves AUTOMACAO_API_KEY conferem"
}

# ----------------------------------------------------------------
# Garante pastas e permissões de escrita no volume storage
# ----------------------------------------------------------------
ensure_storage_dirs() {
    log "Verificando pastas e permissões do storage..."
    $COMPOSE exec -T -u root $APP_SERVICE sh -c '
        mkdir -p storage/app/private/chamados storage/app/private/conciliacoes storage/app/public \
                 storage/framework/cache storage/framework/sessions \
                 storage/framework/views storage/logs bootstrap/cache
        chown -R www-data:www-data storage bootstrap/cache
        chmod -R 775 storage bootstrap/cache
    '
    ok "Storage pronto para escrita"
}

# ----------------------------------------------------------------
# Executa comando no container PHP (serviço app / express-app)
# ----------------------------------------------------------------
wait_for_app() {
    local tentativa
    for tentativa in $(seq 1 30); do
        if $COMPOSE ps --status running "$APP_SERVICE" 2>/dev/null | grep -q "$APP_SERVICE"; then
            return 0
        fi
        sleep 2
    done

    warn "Container PHP não ficou pronto. Últimos logs:"
    $COMPOSE logs --tail 50 "$APP_SERVICE" 2>/dev/null || docker logs --tail 50 "$APP_CONTAINER" 2>/dev/null || true

    return 1
}

exec_app() {
    if $COMPOSE exec -T "$APP_SERVICE" "$@"; then
        return 0
    fi

    docker exec "$APP_CONTAINER" "$@"
}

exec_artisan() {
    exec_app php artisan "$@"
}

# ----------------------------------------------------------------
# Primeiro deploy (build + migrate + seed)
# ----------------------------------------------------------------
cmd_install() {
    log "Iniciando primeiro deploy..."
    check_requirements
    check_api_keys

    log "Construindo imagens Docker..."
    $COMPOSE build --no-cache

    log "Subindo containers..."
    $COMPOSE up -d

    log "Aguardando MySQL e PHP ficarem prontos..."
    sleep 10
    wait_for_app || err "Container app não está rodando — veja: bash deploy.sh logs app"

    log "Rodando migrations..."
    exec_artisan migrate --force

    log "Rodando seeders (admin padrão)..."
    exec_artisan db:seed --force

    log "Criando link de storage..."
    exec_artisan storage:link

    log "Gerando chave de aplicação (se vazia)..."
    APP_KEY=$(grep "^APP_KEY=" .env | cut -d'=' -f2-)
    if [ -z "$APP_KEY" ]; then
        exec_artisan key:generate --force
    fi

    log "Otimizando para produção..."
    ensure_storage_dirs
    exec_artisan config:cache
    exec_artisan route:cache
    exec_artisan view:cache

    ok "Deploy concluído!"
    cmd_status
}

# ----------------------------------------------------------------
# Verifica DOCKER_GID para SSL automático pelo painel
# ----------------------------------------------------------------
check_docker_gid() {
    if [ -f ".env" ] && grep -q "^TENANT_SSL_AUTO_PROVISION=true" .env 2>/dev/null; then
        local gid_atual
        gid_atual=$(grep "^DOCKER_GID=" .env 2>/dev/null | cut -d'=' -f2- || true)
        local gid_host
        gid_host=$(stat -c '%g' /var/run/docker.sock 2>/dev/null || true)

        if [ -n "$gid_host" ] && [ "$gid_atual" != "$gid_host" ]; then
            warn "SSL automático: defina DOCKER_GID=$gid_host no .env e use docker-compose.ssl.yml"
        fi
    fi
}

# ----------------------------------------------------------------
# Atualização (pull + rebuild + migrate)
# ----------------------------------------------------------------
cmd_update() {
    log "Atualizando aplicação..."
    check_requirements
    check_docker_gid

    log "Baixando código mais recente..."
    git pull origin main 2>/dev/null || warn "git pull falhou — verifique manualmente"
    log "Versão no servidor: $(git log -1 --oneline 2>/dev/null || echo 'desconhecida')"

    log "Reconstruindo imagens alteradas..."
    $COMPOSE build

    log "Reiniciando containers..."
    $COMPOSE up -d --remove-orphans

    log "Reiniciando PHP (OPcache não recarrega arquivos sem restart)..."
    $COMPOSE restart app queue queue-conciliacao scheduler 2>/dev/null || $COMPOSE restart app 2>/dev/null || true

    log "Aguardando app subir..."
    wait_for_app || err "Container app não está rodando — veja: bash deploy.sh logs app"

    log "Rodando migrations..."
    exec_artisan migrate --force

    log "Limpando e recriando caches..."
    ensure_storage_dirs
    exec_artisan optimize:clear
    exec_artisan config:cache
    exec_artisan route:cache
    exec_artisan view:cache

    ok "Atualização concluída!"
    cmd_status
}

# ----------------------------------------------------------------
# Só migrations
# ----------------------------------------------------------------
cmd_migrate() {
    log "Rodando migrations..."
    wait_for_app || err "Container app não está rodando"
    exec_artisan migrate --force
    ok "Migrations concluídas"
}

# ----------------------------------------------------------------
# Logs
# ----------------------------------------------------------------
cmd_logs() {
    SERVICE="${2:-}"
    if [ -n "$SERVICE" ]; then
        $COMPOSE logs -f "$SERVICE"
    else
        $COMPOSE logs -f app queue queue-conciliacao automacao
    fi
}

# ----------------------------------------------------------------
# Status
# ----------------------------------------------------------------
cmd_status() {
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "  Status dos containers"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    $COMPOSE ps
    echo ""

    # Testa saúde da automação
    if $COMPOSE ps automacao | grep -q "Up"; then
        HEALTH=$($COMPOSE exec -T automacao curl -s http://localhost:8001/health 2>/dev/null || echo '{}')
        if echo "$HEALTH" | grep -q '"ok":true'; then
            ok "API Automação: saudável"
        else
            warn "API Automação: não respondeu (pode estar iniciando)"
        fi

        ACEITAR=$($COMPOSE exec -T automacao curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8001/aceitar-proposta 2>/dev/null || echo "000")
        if [ "$ACEITAR" = "404" ]; then
            warn "Endpoint /aceitar-proposta não encontrado — rode: docker compose build automacao && docker compose up -d automacao"
        elif [ "$ACEITAR" = "401" ] || [ "$ACEITAR" = "422" ]; then
            ok "API Automação: endpoint /aceitar-proposta disponível"
        fi

        SCREENSHOTS=$($COMPOSE exec -T automacao curl -s -o /dev/null -w "%{http_code}" http://localhost:8001/jobs/test/screenshots 2>/dev/null || echo "000")
        if [ "$SCREENSHOTS" = "404" ]; then
            warn "Endpoint /jobs/{id}/screenshots não encontrado — rode: docker compose up -d --force-recreate automacao"
        elif [ "$SCREENSHOTS" = "401" ] || [ "$SCREENSHOTS" = "422" ]; then
            ok "API Automação: endpoint de screenshots disponível"
        fi
    fi
}

# ----------------------------------------------------------------
# Parar tudo
# ----------------------------------------------------------------
cmd_down() {
    log "Parando todos os containers..."
    $COMPOSE down
    ok "Containers parados"
}

# ----------------------------------------------------------------
# Backup do banco
# ----------------------------------------------------------------
cmd_backup() {
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    BACKUP_FILE="backup_${TIMESTAMP}.sql.gz"

    log "Criando backup: $BACKUP_FILE"
    DB_USER=$(grep "^DB_USERNAME=" .env | cut -d'=' -f2-)
    DB_PASS=$(grep "^DB_PASSWORD=" .env | cut -d'=' -f2-)
    DB_NAME=$(grep "^DB_DATABASE=" .env | cut -d'=' -f2-)

    $COMPOSE exec -T mysql mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip > "$BACKUP_FILE"
    ok "Backup salvo em: $BACKUP_FILE"
}

# ----------------------------------------------------------------
# SSL de domínio personalizado (marketplace)
# ----------------------------------------------------------------
cmd_provision_ssl() {
    DOMAIN="${2:?Informe o domínio, ex: bash deploy.sh provision-ssl julio.com.br}"
    bash scripts/provision-tenant-ssl.sh "$DOMAIN"
}

# ----------------------------------------------------------------
# Artisan helper
# ----------------------------------------------------------------
cmd_artisan() {
    shift
    exec_artisan "$@"
}

# ----------------------------------------------------------------
# Main
# ----------------------------------------------------------------
COMMAND="${1:-help}"

case "$COMMAND" in
    install)  cmd_install ;;
    update)   cmd_update ;;
    migrate)  cmd_migrate ;;
    logs)     cmd_logs "$@" ;;
    status)   cmd_status ;;
    down)     cmd_down ;;
    backup)   cmd_backup ;;
    provision-ssl) cmd_provision_ssl "$@" ;;
    artisan)  cmd_artisan "$@" ;;
    *)
        echo ""
        echo "Uso: bash deploy.sh <comando>"
        echo ""
        echo "Comandos disponíveis:"
        echo "  install   — Primeiro deploy (build + migrate + seed)"
        echo "  update    — Atualiza código e reinicia (git pull + migrate)"
        echo "  migrate   — Roda apenas as migrations"
        echo "  status    — Mostra status dos containers"
        echo "  logs      — Acompanha logs (add nome do serviço para filtrar)"
        echo "  down      — Para todos os containers"
        echo "  backup    — Faz backup do banco de dados"
        echo "  provision-ssl — Emite SSL Let's Encrypt para domínio de marketplace"
        echo "  artisan   — Executa php artisan no container app"
        echo ""
        echo "Exemplos:"
        echo "  bash deploy.sh install"
        echo "  bash deploy.sh logs queue"
        echo "  bash deploy.sh artisan tinker"
        echo ""
        ;;
esac
