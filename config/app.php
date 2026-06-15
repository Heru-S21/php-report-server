<?php

return [
    'debug' => false,
    'data_path' => __DIR__ . '/../data',
    'sqlite_path' => __DIR__ . '/../data/reporting.sqlite',
    'encryption_method' => 'AES-256-CBC',
    'default_pdf_engine' => 'mpdf',
    'max_upload_size' => 1048576, // 1 MB default
    'default_margins' => [
        'top' => 10,
        'bottom' => 10,
        'left' => 15,
        'right' => 15,
    ],
    'date_format' => 'Y-m-d',
    'number_format_decimals' => 2,
    'number_format_dec_point' => '.',
    'number_format_thousands_sep' => ',',

    /*
     * Authentication — set enabled=true to require login
     * For production, change the password to something secure.
     */
    'auth' => [
        'enabled' => false,
        'username' => 'admin',
        'password' => 'admin',
    ],
];
