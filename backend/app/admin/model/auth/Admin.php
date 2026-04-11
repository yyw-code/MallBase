<?php

declare (strict_types=1);

namespace app\admin\model\auth;

use mall_base\base\BaseModel;

/**
 * 管理员模型
 */
class Admin extends BaseModel
{

    const SUPER_ADMIN_ID = 1; // 超级管理员ID

    protected $name = 'admin';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 密码加密器
    protected $passwordHash = 'password_hash';

    protected array $append = [
        'avatar_full_url'
    ];

    /**
     * 隐藏密码字段
     */
    public function hidden(array $hidden = [], bool $merge = false): array
    {
        if ($merge) {
            return array_merge($hidden, ['password']);
        }
        return ['password'];
    }

    /**
     * 验证密码
     */
    public function checkPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * 设置密码
     */
    public function setPasswordAttr(string $password): string
    {
        return $this->password = password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * 检查密码是否需要重新哈希
     */
    public function needsRehash(): bool
    {
        return password_needs_rehash($this->password, PASSWORD_DEFAULT);
    }

    public function getAvatarFullUrlAttr($value, $data)
    {
        return buildUploadUrl($data['avatar'] ?? '');
    }
}
