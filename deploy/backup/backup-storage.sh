#!/usr/bin/env bash
# ==============================================================================
# Capeonn Automated Storage & User Uploads Backup Script
# Archives documents, project files, avatars, and attachments with retention
# ==============================================================================

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/capeonn/capeonn-backend}"
STORAGE_DIR="${APP_DIR}/storage/app"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/capeonn/storage}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
TIMESTAMP="$(date +'%Y-%m-%d_%H%M%S')"
ARCHIVE_FILE="${BACKUP_DIR}/capeonn_storage_${TIMESTAMP}.tar.gz"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

echo "================================================================="
echo " Starting Capeonn Storage Asset Backup [${TIMESTAMP}]"
echo " Source: ${STORAGE_DIR}"
echo " Destination: ${ARCHIVE_FILE}"
echo "================================================================="

if [ ! -d "$STORAGE_DIR" ]; then
    echo "[ERROR] Source storage directory does not exist: ${STORAGE_DIR}"
    exit 1
fi

echo "[1/3] Archiving user uploads, attachments, and documents..."
tar --exclude='storage/app/public/.gitignore' \
    -czf "${ARCHIVE_FILE}" \
    -C "${APP_DIR}/storage" app

chmod 600 "${ARCHIVE_FILE}"
ARCHIVE_SIZE=$(du -h "${ARCHIVE_FILE}" | cut -f1)
echo "[SUCCESS] Storage archive created successfully (${ARCHIVE_SIZE})."

# Prune old storage archives
echo "[2/3] Pruning storage backups older than ${RETENTION_DAYS} days..."
find "${BACKUP_DIR}" -type f -name "capeonn_storage_*.tar.gz" -mtime +"${RETENTION_DAYS}" -exec rm -f {} \; -print

# Optional S3 / Cloud Sync
if [ "${SYNC_OFFSITE:-false}" = "true" ] && command -v aws >/dev/null 2>&1; then
    echo "[3/3] Synchronizing storage archive to offsite S3 bucket: ${S3_BUCKET_NAME}..."
    aws s3 cp "${ARCHIVE_FILE}" "s3://${S3_BUCKET_NAME}/storage/$(basename "${ARCHIVE_FILE}")" --sse AES256
else
    echo "[3/3] Offsite sync skipped."
fi

echo "================================================================="
echo " Storage backup routine completed successfully!"
echo "================================================================="
