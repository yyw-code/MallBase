<script lang="ts" setup>
defineOptions({ name: 'RefundOrderManagement' });
</script>

<template>
  <div class="p-4">
    <a-result status="info" title="售后模块开发中">
      <template #subTitle>
        <div class="text-sm text-gray-500">
          <p>
            后端表
            <code>mb_refund_order</code>
            与权限码 (<code>SystemRefundOrderList</code> /
            <code>SystemRefundOrderApprove</code> /
            <code>SystemRefundOrderReject</code>) 已预注册。
          </p>
          <p class="mt-2">下阶段将实现：售后申请列表、审核同意、驳回与退款结算。</p>
        </div>
      </template>
    </a-result>
  </div>
</template>
