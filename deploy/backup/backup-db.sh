#!/usr/bin/env bash
# ==============================================================================
# Capeonn Automated Database Backup & Retention Script
# Daily Compressed MySQL Dump with 14-Day Rotation & Optional Cloud Sync
# ==============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/capeonn/capeonn-backend}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/capeonn/db}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP="$(date +'%Y-%m-%d_%H%M%S')"
BACKUP_FILE="${BACKUP_DIR}/capeonn_db_${TIMESTAMP}.sql.gz"

# Create backup storage directory
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

echo "================================================================="
echo " Starting Capeonn Database Backup [${TIMESTAMP}]"
echo " Target destination: ${BACKUP_FILE}"
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

if [ -z "${DB_DATABASE}" ] || [ -z "${DB_USERNAME}" ]; then
    echo "[ERROR] Database name or username missing from environment configuration."
    exit 1
fi

echo "[1/3] Executing mysqldump with gzip compression..."
export MYSQL_PWD="${DB_PASSWORD}"

mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USERNAME}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    "${DB_DATABASE}" | gzip -9 > "${BACKUP_FILE}"

unset MYSQL_PWD

BACKUP_SIZE=$(du -h "${BACKUP_FILE}" | cut -f1)
echo "[SUCCESS] Database backup created successfully (${BACKUP_SIZE})."

# Set strict permissions
chmod 600 "${BACKUP_FILE}"

# 2. Prune old backups exceeding retention days
echo "[2/3] Pruning database snapshots older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -type f -name "capeonn_db_*.sql.gz" -mtime +"${RETENTION_DAYS}" -exec rm -f {} \; -print

# 3. Optional Remote / Cloud Storage Sync (AWS S3, Cloudflare R2, or remote rsync)
if [ "${SYNC_OFFSITE:-false}" = "true" ] && command -v aws >/dev/null 2>&1; then
    echo "[3/3] Synchronizing backup to offsite S3 bucket: ${S3_BUCKET_NAME}..."
    aws s3 cp "${BACKUP_FILE}" "s3://${S3_BUCKET_NAME}/database/$(basename "${BACKUP_FILE}")" --sse AES256
else
    echo "[3/3] Offsite sync skipped (set SYNC_OFFSITE=true to enable AWS S3 sync)."
fi

echo "================================================================="
echo " Database backup routine completed successfully!"
echo "================================================================="
