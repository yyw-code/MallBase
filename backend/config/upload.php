<?php

declare (strict_types=1);

// 文件上传配置
return [
    // 默认上传驱动：local（本地）、oss（阿里云OSS）、cos（腾讯云COS）
    'driver' => 'local',

    // 本地存储配置
    'local' => [
        'root_path' => '',
        'url_prefix' => '/uploads',
        'base_url' => 'http://127.0.0.1:8080', // 完整的基础URL，用于返回给前端
    ],

    // 阿里云 OSS 配置
    'oss' => [
        'accessKeyId' => '',
        'accessKeySecret' => '',
        'bucket' => '',
        'endpoint' => '',
        'urlPrefix' => '',
    ],

    // 腾讯云 COS 配置
    'cos' => [
        'secretId' => '',
        'secretKey' => '',
        'region' => '',
        'bucket' => '',
        'urlPrefix' => '',
    ],

    // ==================== 上传规则配置（前端通过 GET /upload/config?type=xxx 获取） ====================

    // 上传类型规则
    'rules' => [
        // 单张图片
        'image' => [
            'max_size'     => 2,    // MB
            'max_count'    => 1,
            'accept_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ],
        // 多张图片
        'images' => [
            'max_size'     => 5,    // MB（单张）
            'max_count'    => 9,
            'accept_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ],
        // 单个文件
        'file' => [
            'max_size'     => 10,   // MB
            'max_count'    => 1,
            'accept_types' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
                'application/x-rar-compressed',
                'text/plain',
                'video/mp4',
                'audio/mpeg',
            ],
        ],
        // 多个文件
        'files' => [
            'max_size'     => 10,   // MB（单个）
            'max_count'    => 5,
            'accept_types' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
                'application/x-rar-compressed',
                'text/plain',
                'video/mp4',
                'audio/mpeg',
            ],
        ],
    ],

    // - 当前用的是 __Emoji__ 字符（📕📝📊📦 等），直接在 `icon` 字段传 emoji 即可
    //- 后端备注可以写：`icon 字段支持 Emoji 字符或 Ant Design 图标名，参考 https://www.antdv.com/components/icon`
    // 文件图标映射（前端根据文件扩展名显示对应图标）
    'file_icons' => [
        ['ext' => 'pdf', 'icon' => '📕'],
        ['ext' => 'doc', 'icon' => '📝'],
        ['ext' => 'docx', 'icon' => '📝'],
        ['ext' => 'xls', 'icon' => '📊'],
        ['ext' => 'xlsx', 'icon' => '📊'],
        ['ext' => 'ppt', 'icon' => '📊'],
        ['ext' => 'pptx', 'icon' => '📊'],
        ['ext' => 'zip', 'icon' => '📦'],
        ['ext' => 'rar', 'icon' => '📦'],
        ['ext' => 'txt', 'icon' => '📄'],
        ['ext' => 'mp4', 'icon' => '🎬'],
        ['ext' => 'mp3', 'icon' => '🎵'],
    ],
];