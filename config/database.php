<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Connection เริ่มต้น
    |--------------------------------------------------------------------------
    |
    | ตั้งที่ DB_CONNECTION ใน .env — ใช้ sqlsrv สำหรับ SQL Server
    |
    */

    'default' => env('DB_CONNECTION', 'sqlsrv'),

    /*
    |--------------------------------------------------------------------------
    | คำนำหน้าชื่อตาราง
    |--------------------------------------------------------------------------
    |
    | ฐานข้อมูลนี้มีระบบเดิมที่ใช้ pos_ อยู่แล้ว ระบบนี้จึงใช้ POS2_
    | ครอบทุกตารางรวม migrations / cache / sessions / jobs ด้วย
    |
    | prefix_indexes = true ทำให้ชื่อ index ที่ Laravel ตั้งเองมีคำนำหน้าตาม
    | ส่วน index ที่ระบุชื่อเองใน migration (เช่น mgp_unique) จะใช้ชื่อนั้นตรง ๆ
    | ซึ่งไม่ชนกัน เพราะ SQL Server ผูกชื่อ index ไว้กับตาราง ไม่ใช่ทั้งฐานข้อมูล
    |
    */

    'prefix' => env('DB_PREFIX', 'POS2_'),

    'connections' => [

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'foodpos'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => env('DB_PREFIX', 'POS2_'),
            'prefix_indexes' => true,

            // ODBC Driver 18 เปิด encrypt เป็นค่าเริ่มต้น เครื่องภายในที่ใช้
            // self-signed certificate ต้องตั้ง trust เป็น true ไม่งั้นต่อไม่ติด
            'encrypt' => env('DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'foodpos'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => env('DB_PREFIX', 'POS2_'),
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => env('DB_PREFIX', 'POS2_'),
            'prefix_indexes' => true,
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | ตารางบันทึกว่า migration ไหนรันไปแล้ว
    |--------------------------------------------------------------------------
    |
    | ตัวนี้ Laravel สร้างเอง ไม่ได้มาจากไฟล์ใน database/migrations
    | และรับ prefix ด้วย จึงกลายเป็น POS2_migrations
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | ยังไม่ได้ใช้ในโปรเจคนี้ (cache/queue/session ใช้ database)
    | เก็บไว้เผื่อย้ายภายหลัง
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'foodpos'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
