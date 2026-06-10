<?php

return [
    'debug' => false,
    'data_path' => __DIR__ . '/../data',
    'sqlite_path' => __DIR__ . '/../data/reporting.sqlite',
    'encryption_method' => 'AES-256-CBC',
    'default_pdf_engine' => 'mpdf',
    'max_upload_size' => 1048576, // 1 MB default
    'date_format' => 'Y-m-d',
    'number_format_decimals' => 2,
    'number_format_dec_point' => '.',
    'number_format_thousands_sep' => ',',
];
