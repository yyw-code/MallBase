<?php

namespace mall_base\base;

use think\Model;

/**
 * 模型基类
 * 
 * 功能说明：
 * - 提供模型层的通用基类
 * - 继承 ThinkPHP Model，保持原生功能
 * - 为后续扩展预留空间
 * 
 * 设计理念：
 * - 简单直接，不封装额外方法
 * - 保持 ThinkPHP Model 的所有原生能力
 * - 作为项目模型类的统一基类
 * 
 * 使用示例：
 * ```php
 * class User extends BaseModel
 * {
 *     protected $table = 'user';
 *     
 *     // 直接使用 ThinkPHP Model 的所有方法
 *     public function getList()
 *     {
 *         return $this->where('status', 1)->select();
 *     }
 * }
 * ```
 */
abstract class BaseModel extends Model
{
    // 不需要封装额外方法，保持 ThinkPHP Model 的原生功能
}
