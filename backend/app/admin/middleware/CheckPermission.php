<?php

declare (strict_types=1);

namespace app\admin\middleware;

use app\admin\model\auth\Permission;
use Closure;
use mall_base\exception\AuthException;
use think\facade\Cache;
use think\Request;
use think\Response;

/**
 * 权限检查中间件
 */
class CheckPermission
{
    /**
     * 超级管理员ID（拥有所有权限）
     */
    protected int $superAdminId = 1;

    /**
     * 缓存前缀
     */
    protected string $cachePrefix = 'admin:permissions:';

    /**
     * 缓存时间（秒）
     */
    protected int $cacheExpire = 3600;

    /**
     * 是否启用缓存
     */
    protected bool $enableCache = true;

    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws AuthException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 获取当前管理员ID（从 JWT 中间件注入）
        $adminId = $request->admin_id ?? null;

        if (empty($adminId)) {
            throw new AuthException('未登录或登录已过期');
        }

        // 超级管理员拥有所有权限
        if ($adminId == $this->superAdminId) {
            return $next($request);
        }

        // 获取当前路由权限标识
        $permissionCode = $this->getPermissionCode($request);

        if (empty($permissionCode)) {
            // 如果没有权限标识，直接通过（可能是公共接口）
            return $next($request);
        }

        // 检查用户是否有该权限
        if (!$this->hasPermission($adminId, $permissionCode)) {
            throw new AuthException('没有权限访问该接口', 400);
        }

        return $next($request);
    }

    /**
     * 获取当前路由权限标识
     *
     * @param Request $request
     * @return string|null
     */
    protected function getPermissionCode(Request $request): ?string
    {
        // 从路由规则中获取权限标识
        $route = $request->rule();

        $options = $route->getOption();
        if ($route) {
            // 路由规则示例：admin/auth/admin/list
            $rule = $route->getRule();

            // 将路由转换为权限标识：admin.auth.admin.list
            return str_replace('/', '.', $rule);
        }

        return null;
    }

    /**
     * 检查用户是否有指定权限
     *
     * @param int $adminId 管理员ID
     * @param string $permissionCode 权限标识
     * @return bool
     */
    protected function hasPermission(int $adminId, string $permissionCode): bool
    {
        // 获取用户的所有权限
        $permissions = $this->getUserPermissions($adminId);

        var_dump($permissions, $permissionCode);
        // 检查是否包含当前权限
        return in_array($permissionCode, $permissions);
    }

    /**
     * 获取用户的所有权限（带缓存）
     *
     * @param int $adminId 管理员ID
     * @return array
     */
    protected function getUserPermissions(int $adminId): array
    {
        $cacheKey = $this->cachePrefix . $adminId;

//        // 尝试从缓存获取
//        if ($this->enableCache) {
//            $cached = Cache::get($cacheKey);
//            if ($cached !== false) {
//                return $cached;
//            }
//        }

        // 查询数据库获取用户权限
        $permissions = Permission::alias('p')
            ->join('role_permission rp', 'rp.permission_id = p.id')
            ->join('admin_role ar', 'ar.id = rp.role_id')
            ->where('ar.admin_id', $adminId)
            ->where('p.status', 1)
            ->column('p.code');


        // 存入缓存
        if ($this->enableCache) {
            Cache::set($cacheKey, $permissions, $this->cacheExpire);
        }

        return $permissions ?: [];
    }

    /**
     * 清除用户权限缓存
     *
     * @param int $adminId 管理员ID
     * @return bool
     */
    public static function clearCache(int $adminId): bool
    {
        $cachePrefix = 'admin:permissions:';
        $cacheKey = $cachePrefix . $adminId;
        return Cache::delete($cacheKey);
    }
}
