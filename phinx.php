<?php
require_once __DIR__.'/../../../config/config.php';
return array(
    'paths' => array(
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seed',
    ),
    'environments' => array(
        'default' => array(
            'adapter' => (defined('DB_CONNECTOR')?DB_CONNECTOR:'mysql'), // mysql or sqlite
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASSWORD,
            'charset' => 'utf8',
            'collation' => 'utf8_general_ci',
            'table_prefix' => defined('DB_PREFIX') ? DB_PREFIX : 'pragma_',
        ),
    ),
);
