<?php
declare(strict_types=1);

namespace app\client\model;

use mall_base\base\BaseModel;

/**
 * 前台用户模型
 */
class User extends BaseModel
{
    protected $name = 'user';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $deleteTime = 'delete_time'; // 软删除

    /**
     * 密码修改器（自动加密）
     */
    public function setPasswordAttr(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码
     */
    public function checkPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * 自动追加头像完整URL
     */
    protected array $append = ['avatar_full_url'];

    public function getAvatarFullUrlAttr($value, $data): string
    {
        if (empty($data['avatar'])) {
            return '';
        }
        if (strpos($data['avatar'], 'http') === 0) {
            return $data['avatar'];
        }
        return request()->domain() . $data['avatar'];
    }
}
