<?php

declare (strict_types=1);

// 图片 MIME（常见 jpg/jpeg/png/gif/webp）
$imageAcceptTypes = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
];

// 文档与压缩包 MIME（常见办公与归档格式）
$documentAcceptTypes = [
    // Office / WPS 常见
    'application/pdf',
    'application/msword', // .doc
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    'application/vnd.ms-excel', // .xls
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
    'application/vnd.ms-powerpoint', // .ppt
    'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
    // 压缩包
    'application/zip', // .zip
    'application/x-zip-compressed', // .zip（兼容）
    'application/vnd.rar', // .rar
    'application/x-rar', // .rar（兼容）
    'application/x-rar-compressed', // .rar（兼容）
    'application/x-7z-compressed', // .7z
    'application/x-tar', // .tar
    'application/gzip', // .gz
    // 文本 / 音频
    'text/plain', // .txt
    'text/csv', // .csv
    'application/csv', // .csv（兼容）
    'audio/mpeg', // .mp3
    'audio/mp3', // .mp3（兼容）
];

// 视频 MIME（国内常见格式）
$videoAcceptTypes = [
    'video/mp4', // .mp4
    'video/quicktime', // .mov
    'video/x-msvideo', // .avi
    'video/x-matroska', // .mkv
    'video/x-flv', // .flv
    'video/x-ms-wmv', // .wmv
    'video/webm', // .webm
    'video/mp2t', // .ts
];

// 文件上传默认配置（代码级兜底）
// 用户日常可调项优先走系统设置；本文件保留驱动默认值、规则模板与安装期兜底。
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
            'accept_types' => $imageAcceptTypes,
        ],
        // 多张图片
        'images' => [
            'max_size'     => 5,    // MB（单张）
            'max_count'    => 9,
            'accept_types' => $imageAcceptTypes,
        ],
        // 单个文件
        'file' => [
            'max_size'     => 10,   // MB
            'max_count'    => 1,
            'accept_types' => $documentAcceptTypes,
        ],
        // 多个文件
        'files' => [
            'max_size'     => 10,   // MB（单个）
            'max_count'    => 5,
            'accept_types' => $documentAcceptTypes,
        ],
        // 单个视频
        'video' => [
            'max_size'     => 200,  // MB
            'max_count'    => 1,
            'accept_types' => $videoAcceptTypes,
        ],
        // 多个视频
        'videos' => [
            'max_size'     => 200,  // MB（单个）
            'max_count'    => 5,
            'accept_types' => $videoAcceptTypes,
        ],
    ],
];
