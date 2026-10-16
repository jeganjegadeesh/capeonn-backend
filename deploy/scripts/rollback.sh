#!/usr/bin/env bash
# ==============================================================================
# Capeonn Automated Production Rollback Pipeline
# Rolls back code, recalculates caches, and restarts background processes
# ==============================================================================

set -euo pipefail

APP_DIR="${1:-/var/www/capeonn/capeonn-backend}"
TARGET_COMMIT="${2:-HEAD~1}"

echo "================================================================="
echo " Initiating Capeonn Production Rollback to: ${TARGET_COMMIT}"
echo "================================================================="

cd "${APP_DIR}"

php artisan down --render="maintenance" || true

echo "[1/5] Checking out target commit ${TARGET_COMMIT}..."
git checkout "${TARGET_COMMIT}"

echo "[2/5] Re-installing dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet

echo "[3/5] Rebuilding application caches..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[4/5] Restarting background daemons..."
php artisan queue:restart
if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl restart capeonn-worker:* || true
fi

echo "[5/5] Bringing application back online..."
php artisan up

echo "================================================================="
echo " Rollback completed successfully!"
echo " Current commit: $(git rev-parse HEAD)"
echo "================================================================="
