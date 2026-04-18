<?php

declare (strict_types=1);

namespace app\admin\middleware;

use app\model\auth\Admin;
use app\model\auth\Permission;
use app\service\cache\PermissionCacheService;
use Closure;
use mall_base\exception\AuthException;
use think\Request;
use think\Response;

/**
 * 权限检查中间件
 */
class CheckPermission
{

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
        if ($adminId == Admin::SUPER_ADMIN_ID) {
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

        if ($route) {
            return $route->getName();
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
        $cacheService = new PermissionCacheService();

        // 尝试从缓存获取
        $cached = $cacheService->get($adminId);
        if (!empty($cached)) {
            return $cached;
        }

        // 查询数据库获取用户权限
        $permissions = Permission::alias('p')
            ->join('role_permission rp', 'rp.permission_id = p.id')
            ->join('admin_role ar', 'ar.role_id = rp.role_id')
            ->where('ar.admin_id', $adminId)
            ->where('p.status', 1)
            ->column('p.code');

        // 存入缓存
        $cacheService->set($adminId, $permissions ?: []);

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
        $cacheService = new PermissionCacheService();
        return $cacheService->clearUser($adminId);
    }
}
