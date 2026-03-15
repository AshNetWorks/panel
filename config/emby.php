<?php

return [
    // 是否启用Emby功能
    'enabled' => env('EMBY_ENABLED', true),
    
    // API配置
    'api' => [
        'timeout' => env('EMBY_API_TIMEOUT', 30),
        'retry_times' => env('EMBY_API_RETRY_TIMES', 3),
        'retry_delay' => env('EMBY_API_RETRY_DELAY', 1000), // 毫秒
        'user_agent' => env('EMBY_USER_AGENT', 'V2Board-Emby/1.0'),
    ],
    
    // 用户配置
    'user' => [
        'password_length' => env('EMBY_PASSWORD_LENGTH', 12),
        'username_prefix' => env('EMBY_USERNAME_PREFIX', ''),
        'username_suffix' => env('EMBY_USERNAME_SUFFIX', ''),
        'max_accounts_per_user' => env('EMBY_MAX_ACCOUNTS_PER_USER', 5),
    ],
    
    // 同步配置
    'sync' => [
        'enabled' => env('EMBY_SYNC_ENABLED', true),
        'interval_minutes' => env('EMBY_SYNC_INTERVAL_MINUTES', 1440), // 默认24小时(1440分钟)
        'batch_size' => env('EMBY_SYNC_BATCH_SIZE', 50), // 每批处理用户数
        'batch_delay' => env('EMBY_SYNC_BATCH_DELAY', 2), // 批次间隔秒数，防止API过载
        'check_changes' => env('EMBY_SYNC_CHECK_CHANGES', true),
        'max_execution_time' => env('EMBY_SYNC_MAX_EXECUTION_TIME', 600), // 最大执行时间(秒)，1500用户建议10分钟
        'lock_timeout_minutes' => env('EMBY_SYNC_LOCK_TIMEOUT', 180), // 防重叠锁超时时间(分钟)
    ],
    
    // 清理配置
    'cleanup' => [
        'enabled' => env('EMBY_CLEANUP_ENABLED', true),
        'expired_days' => env('EMBY_CLEANUP_EXPIRED_DAYS', 30),
        'batch_size' => env('EMBY_CLEANUP_BATCH_SIZE', 50),
        'confirm_required' => env('EMBY_CLEANUP_CONFIRM_REQUIRED', true),
    ],
    
    // 日志配置
    'logging' => [
        'enabled' => env('EMBY_LOGGING_ENABLED', true),
        'log_file' => env('EMBY_LOG_FILE', 'emby-sync.log'),
        'retention_days' => env('EMBY_LOG_RETENTION_DAYS', 90),
        'max_log_size_mb' => env('EMBY_MAX_LOG_SIZE_MB', 10),
        'keep_logs_lines' => env('EMBY_KEEP_LOGS_LINES', 1000),
        'detailed_logging' => env('EMBY_DETAILED_LOGGING', true),
    ],
    
    // 安全配置
    'security' => [
        'encrypt_passwords' => env('EMBY_ENCRYPT_PASSWORDS', true),
        'encrypt_api_keys' => env('EMBY_ENCRYPT_API_KEYS', true),
        'allowed_ips' => env('EMBY_ALLOWED_IPS', null), // 逗号分隔的IP列表
        'rate_limit' => [
            'enabled' => env('EMBY_RATE_LIMIT_ENABLED', true),
            'max_requests' => env('EMBY_RATE_LIMIT_MAX_REQUESTS', 100),
            'per_minutes' => env('EMBY_RATE_LIMIT_PER_MINUTES', 60),
        ],
    ],
    
    // 监控配置
    'monitoring' => [
        'enabled' => env('EMBY_MONITORING_ENABLED', true),
        'health_check_interval' => env('EMBY_HEALTH_CHECK_INTERVAL', 300), // 秒
        'alert_email' => env('EMBY_ALERT_EMAIL', null),
        'webhook_url' => env('EMBY_WEBHOOK_URL', null),
    ],
    
    // 默认服务器设置
    'defaults' => [
        'max_users_per_server' => env('EMBY_DEFAULT_MAX_USERS', 1000),
        'enable_new_servers' => env('EMBY_ENABLE_NEW_SERVERS', true),
        'auto_balance_users' => env('EMBY_AUTO_BALANCE_USERS', false),
    ]
];