#!/usr/bin/env bash
# ==============================================================================
# Capeonn Disaster Recovery Database Restoration Script
# Restores a compressed SQL dump into MySQL with safety confirmation prompts
# ==============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/capeonn/capeonn-backend}"
DUMP_FILE="${1:-}"
FORCE="${2:-false}"

if [ -z "${DUMP_FILE}" ] || [ ! -f "${DUMP_FILE}" ]; then
    echo "Usage: $0 /path/to/capeonn_db_YYYY-MM-DD_HHMMSS.sql.gz [--force]"
    exit 1
fi

echo "================================================================="
echo " CAPEONN DISASTER RECOVERY: DATABASE RESTORATION"
echo " Target snapshot: ${DUMP_FILE}"
echo "================================================================="

# Extract DB credentials from .env
if [ -f "${APP_DIR}/.env" ]; then
    DB_HOST=$(grep -E '^DB_HOST=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d '"\r')
    DB_PORT=$(grep -E '^DB_PORT=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d '"\r')
    DB_DATABASE=$(grep -E '^DB_DATABASE=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d '"\r')
    DB_USERNAME=$(grep -E '^DB_USERNAME=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d '"\r')
    DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "${APP_DIR}/.env" | cut -d '=' -f2- | tr -d '"\r')
else
    echo "[ERROR] .env file not found at ${APP_DIR}/.env"
    exit 1
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

if [ "${FORCE}" != "--force" ]; then
    echo "WARNING: Restoring will OVERWRITE ALL EXISTING DATA in database '${DB_DATABASE}'!"
    read -rp "Are you absolutely sure you want to proceed? (Type 'RESTORE-NOW' to confirm): " CONFIRM
    if [ "$CONFIRM" != "RESTORE-NOW" ]; then
        echo "Restoration aborted by user."
        exit 0
    fi
fi

echo "[1/3] Putting application into maintenance mode..."
php "${APP_DIR}/artisan" down --render="maintenance" || true

echo "[2/3] Decompressing and importing SQL dump..."
export MYSQL_PWD="${DB_PASSWORD}"

gzip -dc "${DUMP_FILE}" | mysql \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USERNAME}" \
    --default-character-set=utf8mb4 \
    "${DB_DATABASE}"

unset MYSQL_PWD

echo "[3/3] Clearing cached state and bringing application back online..."
php "${APP_DIR}/artisan" config:clear
php "${APP_DIR}/artisan" cache:clear
php "${APP_DIR}/artisan" queue:restart
php "${APP_DIR}/artisan" up

echo "================================================================="
echo " Database restoration completed successfully!"
echo "================================================================="
