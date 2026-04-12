<?php

namespace mall_base\service;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;

/**
 * JWT 服务
 *
 * 纯 JWT 编解码，不涉及缓存逻辑
 * refresh_token 的 Redis 管理由 JwtCacheService 负责
 */
class JwtService
{
    /**
     * JWT 密钥
     */
    protected string $key;

    /**
     * Token 过期时间（秒）
     */
    protected int $expire = 7200; // 2小时

    /**
     * 刷新 Token 过期时间（秒）
     */
    protected int $refreshExpire = 2592000; // 30天

    /**
     * 算法
     */
    protected string $algorithm = 'HS256';

    /**
     * 颁发者
     */
    protected string $issuer = 'mall-admin';

    /**
     * 构造函数
     */
    public function __construct()
    {
        $key = (string) config('jwt.secret', '');
        if (trim($key) === '') {
            throw new \RuntimeException('JWT secret 未配置，请设置 JWT_SECRET 环境变量');
        }

        $this->key = $key;
        $this->expire = config('jwt.expire', 7200);
        $this->refreshExpire = config('jwt.refresh_expire', 2592000);
        $this->algorithm = config('jwt.algorithm', 'HS256');
        $this->issuer = config('jwt.issuer', 'mall-admin');
    }

    /**
     * 生成 Token 对（access_token + refresh_token）
     *
     * @param array $payload 载荷数据（无需传 type，会自动设置）
     * @return array ['access_token', 'refresh_token', 'expires_in', 'refresh_expires_in']
     */
    public function encode(array $payload): array
    {
        $now = time();

        // 自动设置 type，覆盖调用方传入的值
        $accessData = array_merge($payload, ['type' => 'access']);
        $refreshData = array_merge($payload, ['type' => 'refresh']);

        return [
            'access_token' => $this->buildToken($accessData, $this->expire, $now),
            'refresh_token' => $this->buildToken($refreshData, $this->refreshExpire, $now),
            'expires_in' => $this->expire,
            'refresh_expires_in' => $this->refreshExpire,
        ];
    }

    /**
     * 构建单个 Token
     *
     * @param array $payload 载荷数据
     * @param int $expire 过期时间（秒）
     * @param int $now 当前时间戳
     * @return string
     */
    protected function buildToken(array $payload, int $expire, int $now): string
    {
        $tokenPayload = [
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $expire,
            'data' => $payload,
        ];

        return FirebaseJWT::encode($tokenPayload, $this->key, $this->algorithm);
    }

    /**
     * 解析 Token
     *
     * @param string $token Token
     * @return object
     * @throws \Exception
     */
    public function decode(string $token): object
    {
        try {
            return FirebaseJWT::decode($token, new Key($this->key, $this->algorithm));
        } catch (\Exception $e) {
            throw new \Exception('Token 无效或已过期', 401);
        }
    }

    /**
     * 验证 Token
     *
     * @param string $token Token
     * @return bool
     */
    public function verify(string $token): bool
    {
        try {
            $this->decode($token);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 从 Token 中获取用户ID
     *
     * @param string $token Token
     * @return int|null
     */
    public function getUserId(string $token): ?int
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->data->admin_id ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从 Token 中获取用户名
     *
     * @param string $token Token
     * @return string|null
     */
    public function getUsername(string $token): ?string
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->data->username ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从 Token 中获取载荷数据
     *
     * @param string $token Token
     * @return object|null
     */
    public function getPayload(string $token): ?object
    {
        try {
            $decoded = $this->decode($token);
            return $decoded->data ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取 refresh_token 过期时间（秒）
     * 供外部缓存服务使用
     *
     * @return int
     */
    public function getRefreshExpire(): int
    {
        return $this->refreshExpire;
    }
}
