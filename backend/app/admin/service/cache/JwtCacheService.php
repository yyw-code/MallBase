<?php

namespace app\admin\service\cache;

use mall_base\service\JwtCacheService as BaseJwtCacheService;

/**
 * JWT 缓存服务（admin 模块）
 *
 * 继承公共层 JwtCacheService，保持向后兼容
 * @deprecated 请直接使用 mall_base\service\JwtCacheService
 */
class JwtCacheService extends BaseJwtCacheService
{
}
