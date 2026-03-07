import type { Directive, DirectiveBinding } from 'vue';

import { useAccessStore } from '#/modules/access';

/**
 * 权限指令
 * 用于控制按钮等元素的显示/隐藏
 *
 * 使用方式：
 * <a-button v-auth="'user:create'">创建用户</a-button>
 * <a-button v-auth="['user:create', 'user:update']">创建或更新</a-button>
 *
 * @param el 指令绑定的元素
 * @param binding 指令绑定值
 */
const permission: Directive<HTMLElement, string | string[]> = {
  mounted(el: HTMLElement, binding: DirectiveBinding<string | string[]>) {
    checkPermission(el, binding);
  },
  updated(el: HTMLElement, binding: DirectiveBinding<string | string[]>) {
    checkPermission(el, binding);
  },
};

/**
 * 检查权限
 * @param el 元素
 * @param binding 指令绑定值
 */
function checkPermission(
  el: HTMLElement,
  binding: DirectiveBinding<string | string[]>,
) {
  const { value } = binding;

  if (!value) {
    throw new Error('需要指定权限码，例如：v-auth="\'user:create\'"');
  }

  const accessStore = useAccessStore();
  const accessCodes = accessStore.accessCodes || [];

  // 转换为数组格式
  const requiredPermissions = Array.isArray(value) ? value : [value];

  // 检查是否有任一权限
  const hasPermission = requiredPermissions.some((permission) =>
    accessCodes.includes(permission),
  );

  // 如果没有权限，移除元素
  if (!hasPermission) {
    el.remove();
  }
}

export default permission;
