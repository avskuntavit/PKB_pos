# ==========================================
# Stage 1: Build Frontend (Vite + Vue 3)
# ==========================================
FROM node:20-alpine AS frontend
WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# ==========================================
# Stage 2: PHP Application + Nginx
# ==========================================
#
# ── ทำไมเป็น Debian ไม่ใช่ Alpine ──────────────────────────
# ระบบนี้ใช้ SQL Server ซึ่งต้องมีไดรเวอร์ ODBC ของ Microsoft (msodbcsql18)
# Microsoft ปล่อยแพ็กเกจสำหรับ Alpine เฉพาะบางรุ่นเท่านั้น และไม่ตรงกับรุ่นที่
# php:8.2-fpm-alpine ใช้อยู่ ส่วน Debian bookworm อยู่ในรายการที่รองรับอย่างเป็นทางการ
# ภาพใหญ่ขึ้นประมาณ 150MB แลกกับการที่ต่อฐานข้อมูลได้จริง
#
FROM php:8.2-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        gnupg2 \
        ca-certificates \
        apt-transport-https \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libonig-dev \
        unixodbc-dev \
    && rm -rf /var/lib/apt/lists/*

# ไดรเวอร์ ODBC ของ Microsoft — ถ้าไม่มีตัวนี้ pdo_sqlsrv คอมไพล์ไม่ผ่าน
RUN curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
        | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

# pdo_sqlite ยังเก็บไว้ เพราะเทสต์ทั้งชุดรันบน sqlite ในหน่วยความจำ (ดู phpunit.xml)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_sqlite \
        zip \
        bcmath \
        gd \
        intl \
        opcache \
    && pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Debian มีเว็บไซต์ default ของ nginx ติดมาด้วย ต้องเอาออกไม่งั้นชนกับของเรา
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default

COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
