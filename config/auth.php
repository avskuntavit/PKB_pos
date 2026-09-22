<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        // พนักงานร้าน — อีเมล + รหัสผ่าน
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // ลูกค้า — เบอร์ + OTP ไม่มีรหัสผ่าน
        // แยก guard เพื่อให้พนักงานกับลูกค้าล็อกอินพร้อมกันบนเครื่องเดียวได้
        // (พนักงานเปิดหน้าร้านออนไลน์สั่งแทนลูกค้าโดยไม่หลุดจากระบบ)
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        'customers' => [
            'driver' => 'eloquent',
            'model' => App\Models\Customer::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
