<?php
/**
 * Configuration for BTC Address Balance Monitor
 */

return [
    // Bitcoin RPC Settings
    'rpc_host' => '127.0.0.1',
    'rpc_port' => 8332,
    'rpc_user' => 'your_rpc_username',
    'rpc_password' => 'your_rpc_password',

    // Access Password
    'admin_password' => 'admin123', // Change this!

    // Database Settings
    'db_path' => __DIR__ . '/addr_monitor.db',

    // Cron Settings
    'cron_batch_limit' => 50, // Number of addresses to update per cron run

    // Fallback Settings
    'allow_fallback' => true, // Use public API if RPC fails
];
