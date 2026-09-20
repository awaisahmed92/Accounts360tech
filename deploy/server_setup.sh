#!/bin/bash
# ============================================================
# Accounts360tech — Full VPS Setup Script for Ubuntu 24.04
# Run as root. Sets up: Nginx, PHP 8.3, MySQL 8.0, Certbot
# ============================================================
set -e

APP_DIR="/var/www/accounts360techx.com"
DOMAIN="accounts360techx.com"
DB_NAME="accounts360tech"
DB_USER="a360user"
DB_PASS="A360@Secure#2026"
JWT_SECRET=$(openssl rand -hex 32)
ADMIN_HASH='$2y$10$KvbfcmNgb57nqoOa86hkfePJ2LDjGgROH3Zqsh0qrM9RCk7SsgjhW'

echo "=============================="
echo " Step 1: Update system"
echo "=============================="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

echo "=============================="
echo " Step 2: Install Nginx"
echo "=============================="
apt-get install -y -qq nginx

echo "=============================="
echo " Step 3: Install PHP 8.3 + extensions"
echo "=============================="
apt-get install -y -qq software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq \
  php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-fileinfo php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-gd

echo "=============================="
echo " Step 4: Install MySQL 8.0"
echo "=============================="
apt-get install -y -qq mysql-server

echo "=============================="
echo " Step 5: Configure MySQL"
echo "=============================="
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
echo "DB setup done."

echo "=============================="
echo " Step 6: Create app directories"
echo "=============================="
mkdir -p "${APP_DIR}/backend"
mkdir -p "${APP_DIR}/frontend"
mkdir -p "${APP_DIR}/storage/documents"
chmod -R 755 "${APP_DIR}"
chown -R www-data:www-data "${APP_DIR}/storage"

echo "=============================="
echo " Step 7: Configure Nginx"
echo "=============================="
cat > /etc/nginx/sites-available/accounts360techx.com << 'NGINXEOF'
server {
    listen 80;
    server_name accounts360techx.com www.accounts360techx.com;

    # Frontend — SPA
    root /var/www/accounts360techx.com/frontend;
    index index.html;

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # GZIP
    gzip on;
    gzip_types text/plain text/css application/javascript application/json;
    gzip_min_length 1024;

    # Frontend SPA — all routes serve index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Block .env, .sql, and hidden files
    location ~ /\.(env|sql|git|htaccess) {
        deny all;
        return 404;
    }

    # Backend API
    location /api/ {
        alias /var/www/accounts360techx.com/backend/public/;
        try_files $uri $uri/ @php_api;
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $request_filename;
            include fastcgi_params;
        }
    }

    location @php_api {
        rewrite ^/api/(.*)$ /index.php?/$1 last;
    }

    # Direct backend public folder
    location /backend/public/ {
        root /var/www/accounts360techx.com;
        try_files $uri $uri/ /backend/public/index.php?$query_string;
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Static assets with long cache
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|woff|woff2|ttf|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    client_max_body_size 25M;
    access_log /var/log/nginx/accounts360techx.access.log;
    error_log  /var/log/nginx/accounts360techx.error.log;
}
NGINXEOF

# Enable site
ln -sf /etc/nginx/sites-available/accounts360techx.com /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Test config
nginx -t
systemctl reload nginx

echo "=============================="
echo " Step 8: Enable services"
echo "=============================="
systemctl enable nginx php8.3-fpm mysql
systemctl start nginx php8.3-fpm mysql

echo "=============================="
echo " Step 9: Install Certbot"
echo "=============================="
apt-get install -y -qq certbot python3-certbot-nginx

echo "=============================="
echo " Server setup COMPLETE"
echo "=============================="
echo "DB_NAME:  ${DB_NAME}"
echo "DB_USER:  ${DB_USER}"
echo "DB_PASS:  ${DB_PASS}"
echo "JWT_SECRET: ${JWT_SECRET}"
echo "App dir:  ${APP_DIR}"
echo "PHP-FPM socket: /run/php/php8.3-fpm.sock"
