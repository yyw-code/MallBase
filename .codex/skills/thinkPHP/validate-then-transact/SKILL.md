# ThinkPHP 规则：先校验再事务

## 适用范围

适用于 Service 层 `create/update/delete` 等含事务方法。

## 标准结构

```php
public function create(array $data): int
{
    // 1) 事务外校验
    $this->validateBusiness($data);

    // 2) 事务内只写入
    return $this->transaction(function () use ($data) {
        $model = $this->model();
        $model->save($data);
        return $model->id;
    });
}
```

## 禁止项

- ❌ 在事务内做唯一性/存在性校验。
- ❌ 在事务内 `find` 后再抛异常。
- ❌ 在事务内执行日志/缓存/通知等非写入逻辑。

## 自检清单

- [ ] 事务前完成所有业务校验。
- [ ] 事务体内仅包含原子写操作。

## When to Use

在以下场景触发此模式：
- 编写 Service 层的 `create` / `update` / `delete` 方法
- 方法内涉及多表写入需要事务保护
- 使用 `$this->transaction()` 时

## Related

- CLAUDE.md「四点五、事务使用规范」
- `backend/app/admin/service/auth/AdminService.php` — 项目中的标准范例
