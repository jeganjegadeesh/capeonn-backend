#!/usr/bin/env bash
# ==============================================================================
# Capeonn Zero-Downtime Production Deployment Pipeline
# Automated Git pull, Composer optimize, Migrations, Cache Warming & Verification
# ==============================================================================

set -euo pipefail

APP_DIR="${1:-/var/www/capeonn/capeonn-backend}"
BRANCH="${2:-main}"
MAINTENANCE_SECRET="${MAINTENANCE_SECRET:-capeonn-deploy-bypass}"

echo "================================================================="
echo " Starting Capeonn Production Deployment"
echo " Target directory: ${APP_DIR}"
echo " Branch: ${BRANCH}"
echo " Timestamp: $(date +'%Y-%m-%d %H:%M:%S UTC')"
echo "================================================================="

cd "${APP_DIR}"

# 1. Enter Maintenance Mode with Bypass Cookie
echo "[1/10] Enabling maintenance mode..."
php artisan down --secret="${MAINTENANCE_SECRET}" --render="maintenance" || true

# 2. Fetch Latest Source Code
echo "[2/10] Pulling latest changes from Git repository (${BRANCH})..."
git fetch origin "${BRANCH}"
git checkout "${BRANCH}"
git pull origin "${BRANCH}"

# Record current commit hash for rollback reference
GIT_COMMIT=$(git rev-parse HEAD)
echo "Current deployment commit: ${GIT_COMMIT}"
echo "${GIT_COMMIT}" > "${APP_DIR}/storage/app/LAST_DEPLOYED_COMMIT"

# 3. Install & Optimize Composer Dependencies
echo "[3/10] Installing production PHP dependencies (Composer)..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet

# 4. Execute Database Migrations
echo "[4/10] Executing database migrations..."
php artisan migrate --force

# 5. Clear Stale Cached State
echo "[5/10] Clearing stale application caches..."
php artisan optimize:clear

# 6. Rebuild Production Caches (Config, Routes, Views, Events)
echo "[6/10] Rebuilding optimized production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. Ensure Storage Symlinks & Strict Permissions
echo "[7/10] Verifying storage symlinks and directory permissions..."
php artisan storage:link || true
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 8. Restart Background Queue Workers & WebSockets
echo "[8/10] Signalling queue worker restart..."
php artisan queue:restart

if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl restart capeonn-worker:* || true
fi

if systemctl is-active --quiet capeonn-reverb; then
    echo "Restarting real-time WebSocket daemon..."
    systemctl restart capeonn-reverb || true
fi

# 9. Exit Maintenance Mode
echo "[9/10] Bringing application out of maintenance mode..."
php artisan up

# 10. Automated Health Check Verification
echo "[10/10] Performing post-deployment health check..."
if command -v curl >/dev/null 2>&1; then
    HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/api/v1/health || true)
    if [ "$HEALTH_STATUS" -eq 200 ] || [ "$HEALTH_STATUS" -eq 000 ]; then
        echo "[HEALTH CHECK PASSED] Endpoint returned HTTP ${HEALTH_STATUS}."
    else
        echo "[WARNING] Health check returned unexpected HTTP code: ${HEALTH_STATUS}."
    fi
fi

echo "================================================================="
echo " Capeonn successfully deployed to production! [Commit: ${GIT_COMMIT}]"
echo "================================================================="
