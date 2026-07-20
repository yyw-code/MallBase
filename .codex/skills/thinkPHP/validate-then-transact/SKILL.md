---
name: validate-then-transact
description: MallBase ThinkPHP 写操作与事务规则；实现或修改 create、update、delete、状态流转、多表写入、余额库存变更、行锁、条件更新或并发一致性校验时使用。
---

# ThinkPHP 校验与事务边界

## 默认顺序

1. 在事务外完成参数格式、权限、静态业务规则和不依赖锁的存在性检查。
2. 只在需要原子多表写入或并发一致性时开启事务。
3. 在事务内保持查询和写入最少，完成后立即提交。
4. 把远程 API、短信、推送、文件处理等慢或不可回滚的外部 I/O 放到事务外；需要可靠副作用时使用提交后的事件或队列方案。

```php
$this->validateBusiness($data);

return $this->transaction(function () use ($data): int {
    $model = $this->model();
    $model->save($data);
    return (int) $model->id;
});
```

## 并发校验例外

“先校验再事务”不等于禁止事务内校验。余额、库存、订单状态、退款额度等值可能在事务外检查后被并发修改，必须在事务内重新读取可信当前状态：

```php
return $this->transaction(function () use ($id): bool {
    $row = $this->model()->where('id', $id)->lock(true)->find();
    if ($row === null) {
        throw new BusinessException('记录不存在');
    }
    if (!$this->canTransit((int) $row->status)) {
        throw new BusinessException('当前状态不允许操作');
    }
    return $row->save(['status' => 2]);
});
```

在热点计数或库存扣减中，优先使用带条件的原子 `UPDATE`，再根据受影响行数判断是否冲突。唯一约束、条件更新和行锁是并发下的最终防线，事务外预检不能替代它们。

## 禁止项

- 不为单表单次写入机械地扩大事务范围。
- 不在持锁事务内调用外部服务或执行可长时间阻塞的工作。
- 不只依赖事务外读取做状态流转、余额或库存判断。
- 不吞掉事务异常后返回成功。

## 自检

- [ ] 可提前完成的校验已移到事务外。
- [ ] 依赖锁内当前值的校验已在事务内重做。
- [ ] 事务覆盖的写入具有同一原子目标。
- [ ] 外部副作用不会因回滚而与数据库状态分叉。
