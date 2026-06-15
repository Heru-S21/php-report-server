<?php

namespace ReportingEngine\Core;

class SettingsManager
{
    /**
     * Return all editable setting definitions.
     *
     * Each definition has:
     *  - key:         string (matches app_settings DB key)
     *  - label:       string (human-readable)
     *  - description: string (help text)
     *  - type:        string (bool|int|string|password|select)
     *  - default:     mixed
     *  - group:       string (section heading)
     *  - options?:    array (for select type)
     */
    public static function getDefinitions(): array
    {
        return [
            // --- Date & Number Format ---
            [
                'key' => 'date_format',
                'label' => 'Date Format',
                'description' => 'PHP date format string, e.g. Y-m-d, d/m/Y, M j, Y',
                'type' => 'string',
                'default' => 'Y-m-d',
                'group' => 'Date & Number Format',
            ],
            [
                'key' => 'number_format_decimals',
                'label' => 'Decimal Places',
                'description' => 'Number of decimal places for numeric values',
                'type' => 'int',
                'default' => 2,
                'group' => 'Date & Number Format',
            ],
            [
                'key' => 'number_format_dec_point',
                'label' => 'Decimal Separator',
                'description' => 'Character used as decimal point',
                'type' => 'string',
                'default' => '.',
                'group' => 'Date & Number Format',
            ],
            [
                'key' => 'number_format_thousands_sep',
                'label' => 'Thousands Separator',
                'description' => 'Character used as thousands separator',
                'type' => 'string',
                'default' => ',',
                'group' => 'Date & Number Format',
            ],

            // --- Image Upload ---
            [
                'key' => 'max_upload_size',
                'label' => 'Max Upload Size (MB)',
                'description' => 'Maximum file size for image uploads in megabytes. Affects the image library.',
                'type' => 'int',
                'default' => 1048576,
                'group' => 'Image Upload',
            ],

            // --- PDF Engine ---
            [
                'key' => 'pdf_engine',
                'label' => 'PDF Engine',
                'description' => 'Engine used for PDF export',
                'type' => 'select',
                'default' => 'mpdf',
                'options' => ['mpdf' => 'mPDF'],
                'group' => 'PDF Engine',
            ],

            // --- Appearance ---
            [
                'key' => 'theme',
                'label' => 'Theme',
                'description' => 'Choose appearance. You can also toggle via the moon/sun icon in the navbar.',
                'type' => 'select',
                'default' => 'light',
                'options' => ['light' => 'Light', 'dark' => 'Dark'],
                'group' => 'Appearance',
            ],

            // --- General ---
            [
                'key' => 'debug',
                'label' => 'Debug Mode',
                'description' => 'When enabled, detailed error messages are shown. Disable in production.',
                'type' => 'bool',
                'default' => false,
                'group' => 'Developer',
            ],
            [
                'key' => 'encryption_method',
                'label' => 'Encryption Method',
                'description' => 'Cipher used for encrypting connection passwords. Change only if you understand the implications.',
                'type' => 'select',
                'default' => 'AES-256-CBC',
                'options' => ['AES-256-CBC' => 'AES-256-CBC'],
                'group' => 'Developer',
            ],

            // --- Report Defaults ---
            [
                'key' => 'default_margins_top',
                'label' => 'Default Margin Top (mm)',
                'description' => 'Default top margin for new reports',
                'type' => 'int',
                'default' => 10,
                'group' => 'Report Default Margins',
            ],
            [
                'key' => 'default_margins_bottom',
                'label' => 'Default Margin Bottom (mm)',
                'description' => 'Default bottom margin for new reports',
                'type' => 'int',
                'default' => 10,
                'group' => 'Report Default Margins',
            ],
            [
                'key' => 'default_margins_left',
                'label' => 'Default Margin Left (mm)',
                'description' => 'Default left margin for new reports',
                'type' => 'int',
                'default' => 15,
                'group' => 'Report Default Margins',
            ],
            [
                'key' => 'default_margins_right',
                'label' => 'Default Margin Right (mm)',
                'description' => 'Default right margin for new reports',
                'type' => 'int',
                'default' => 15,
                'group' => 'Report Default Margins',
            ],

            // --- Authentication ---
            [
                'key' => 'auth_enabled',
                'label' => 'Require Login',
                'description' => 'When enabled, users must log in before accessing any page.',
                'type' => 'bool',
                'default' => false,
                'group' => 'Authentication',
            ],
            [
                'key' => 'auth_username',
                'label' => 'Username',
                'description' => 'Login username for authentication',
                'type' => 'string',
                'default' => 'admin',
                'group' => 'Authentication',
            ],
            [
                'key' => 'auth_password',
                'label' => 'Password',
                'description' => 'Login password. Leave blank to keep the current password when saving.',
                'type' => 'password',
                'default' => '',
                'group' => 'Authentication',
            ],
        ];
    }

    /**
     * Return key-value pairs of defaults for all editable settings.
     */
    public static function getDefaults(): array
    {
        $defaults = [];
        foreach (self::getDefinitions() as $def) {
            $defaults[$def['key']] = $def['default'];
        }
        return $defaults;
    }

    /**
     * Return the list of valid editable setting keys.
     */
    public static function getEditableKeys(): array
    {
        return array_map(fn($def) => $def['key'], self::getDefinitions());
    }

    /**
     * Return the definition for a single key, or null if not found.
     */
    public static function getDefinition(string $key): ?array
    {
        foreach (self::getDefinitions() as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }
        return null;
    }

    /**
     * Cast a raw value to the proper type for a given setting key.
     * Returns the value as a string suitable for storage in app_settings.
     */
    public static function castValue(string $key, mixed $value): string
    {
        $def = self::getDefinition($key);
        if ($def === null) {
            return (string)$value;
        }

        return match ($def['type']) {
            'bool' => $value ? '1' : '0',
            'int' => (string)(int)$value,
            'float' => (string)(float)$value,
            default => (string)$value,
        };
    }
}
