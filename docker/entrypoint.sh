#!/bin/sh
set -e

cd /var/www/html

# ── โฟลเดอร์ที่ Laravel ต้องเขียนได้ ────────────────────────
mkdir -p storage/framework/cache \
         storage/framework/sessions \
         storage/framework/views \
         storage/logs \
         storage/app/public

# ── ฐานข้อมูล ──────────────────────────────────────────────
# ระบบนี้ใช้ SQL Server เป็นหลัก ไฟล์ sqlite จะถูกสร้างให้เฉพาะตอนที่
# เลือกใช้ sqlite จริง ๆ เท่านั้น เดิมสร้างทิ้งไว้เสมอซึ่งทำให้เข้าใจผิดได้ว่า
# ข้อมูลอยู่ในคอนเทนเนอร์ ทั้งที่ข้อมูลจริงอยู่บนเซิร์ฟเวอร์ฐานข้อมูล
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p database
    touch "${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    chown -R www-data:www-data database
    chmod -R 775 database
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# ── แคชการตั้งค่า ──────────────────────────────────────────
# ไม่ใส่ || true แล้ว ถ้าตั้งค่าผิดต้องรู้ตั้งแต่ตอนบูต
# ไม่ใช่ปล่อยให้แอปขึ้นมาแล้วพังตอนมีลูกค้ายืนรออยู่หน้าเคาน์เตอร์
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link --force

# ── ปรับโครงฐานข้อมูล ──────────────────────────────────────
# ไม่มี --seed แล้ว
#
# เดิมรัน `migrate --force --seed || true` ทุกครั้งที่คอนเทนเนอร์เริ่ม
# ซึ่งหมายถึงยอดขายปลอม 30 วันถูกยัดเข้าระบบใหม่ทุกครั้งที่ deploy
# และ || true ทำให้ความล้มเหลวเงียบหายไปโดยไม่มีใครรู้
#
# ข้อมูลตั้งต้นครั้งแรกให้สั่งเองครั้งเดียว:  php artisan db:seed
# ข้อมูลตัวอย่างสำหรับลองเล่น:               php artisan db:seed --class=DemoSeeder
#
# รอฐานข้อมูลก่อน เพราะ SQL Server มักอยู่คนละเครื่องและอาจยังไม่พร้อม
# ตอนที่คอนเทนเนอร์นี้เริ่ม ลองใหม่ได้เพราะ migrate เรียกซ้ำแล้วไม่เสียหาย
attempt=0
until php artisan migrate --force; do
    attempt=$((attempt + 1))

    if [ "$attempt" -ge 10 ]; then
        echo "ต่อฐานข้อมูลไม่ได้หลังลอง 10 ครั้ง — ตรวจค่า DB_* ใน environment"
        exit 1
    fi

    echo "ยังต่อฐานข้อมูลไม่ได้ รอแล้วลองใหม่ ($attempt/10)"
    sleep 5
done

exec /usr/bin/supervisord -c /etc/supervisord.conf
