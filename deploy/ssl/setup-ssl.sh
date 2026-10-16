#!/usr/bin/env bash
# ==============================================================================
# Capeonn Production SSL / TLS Certificate Automation
# Let's Encrypt Certbot Setup, Auto-Renewal Cron & Diffie-Hellman Generation
# ==============================================================================

set -euo pipefail

DOMAIN="${1:-api.capeonn.com}"
EMAIL="${2:-admin@capeonn.com}"

echo "================================================================="
echo " Setting up Let's Encrypt SSL for domain: ${DOMAIN}"
echo " Admin contact email: ${EMAIL}"
echo "================================================================="

if [[ $EUID -ne 0 ]]; then
   echo "[ERROR] This script must be run as root or with sudo." 
   exit 1
fi

# 1. Update packages and install Certbot + Nginx plugin
echo "[1/5] Installing Certbot and Nginx plugin..."
apt-get update -y
apt-get install -y certbot python3-certbot-nginx

# 2. Ensure ACME challenge directory exists
echo "[2/5] Creating ACME challenge webroot directory..."
mkdir -p /var/www/certbot
chown -R www-data:www-data /var/www/certbot
chmod -R 755 /var/www/certbot

# 3. Generate Diffie-Hellman Parameters (4096-bit or 2048-bit for modern TLS)
DH_PARAM="/etc/ssl/certs/dhparam.pem"
if [ ! -f "$DH_PARAM" ]; then
    echo "[3/5] Generating Diffie-Hellman parameters (2048-bit)..."
    openssl dhparam -out "$DH_PARAM" 2048
    chmod 600 "$DH_PARAM"
else
    echo "[3/5] Diffie-Hellman parameters already exist at ${DH_PARAM}. Skipping generation."
fi

# 4. Request / Renew Certificate
echo "[4/5] Requesting Let's Encrypt SSL Certificate..."
certbot certonly --webroot \
    -w /var/www/certbot \
    -d "${DOMAIN}" \
    --email "${EMAIL}" \
    --agree-tos \
    --no-eff-email \
    --keep-until-expiring \
    --non-interactive

# 5. Setup Automated Daily Renewal Hook
echo "[5/5] Configuring automated certificate renewal cron..."
CRON_FILE="/etc/cron.d/capeonn_certbot_renewal"
cat << 'EOF' > "$CRON_FILE"
# Check certificate validity daily at 03:30 AM and reload Nginx if renewed
30 3 * * * root certbot renew --quiet --post-hook "systemctl reload nginx"
EOF
chmod 644 "$CRON_FILE"

# Test Nginx and reload
echo "[SUCCESS] Testing Nginx configuration..."
nginx -t
systemctl reload nginx

echo "================================================================="
echo " SSL Certificate successfully provisioned for ${DOMAIN}!"
echo " Certificates located in: /etc/letsencrypt/live/${DOMAIN}/"
echo " Automated renewal cron configured in: ${CRON_FILE}"
echo "================================================================="
