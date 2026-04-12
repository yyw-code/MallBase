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
            'accept_types' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
        ],
        // 多张图片
        'images' => [
            'max_size'     => 5,    // MB（单张）
            'max_count'    => 9,
            'accept_types' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
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
                'application/x-zip-compressed',
                'application/vnd.rar',
                'application/x-rar',
                'application/x-rar-compressed',
                'application/x-7z-compressed',
                'application/x-tar',
                'application/gzip',
                'text/plain',
                'text/csv',
                'application/csv',
                'audio/mpeg',
                'audio/mp3',
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
                'application/x-zip-compressed',
                'application/vnd.rar',
                'application/x-rar',
                'application/x-rar-compressed',
                'application/x-7z-compressed',
                'application/x-tar',
                'application/gzip',
                'text/plain',
                'text/csv',
                'application/csv',
                'audio/mpeg',
                'audio/mp3',
            ],
        ],
        // 单个视频
        'video' => [
            'max_size'     => 200,  // MB
            'max_count'    => 1,
            'accept_types' => [
                'video/mp4',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-matroska',
                'video/x-flv',
                'video/x-ms-wmv',
                'video/webm',
                'video/mp2t',
            ],
        ],
        // 多个视频
        'videos' => [
            'max_size'     => 200,  // MB（单个）
            'max_count'    => 5,
            'accept_types' => [
                'video/mp4',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-matroska',
                'video/x-flv',
                'video/x-ms-wmv',
                'video/webm',
                'video/mp2t',
            ],
        ],
    ],
];
