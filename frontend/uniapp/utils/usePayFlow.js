import { ref } from 'vue'
import { getPayMethods } from '@/api/config'
import { triggerPay } from '@/utils/payment'
import { getPlatform } from '@/utils/platform'

/**
 * 支付方式选择 + 拉起支付的统一流程
 *
 * 行为：
 *  - startPay(orderId) 拉取 /client/api/setting/payMethods
 *      - 0 个：Toast 提示，不发起请求
 *      - 1 个：自动选择并直接 triggerPay
 *      - 2 个及以上：弹出 mb-pay-method-sheet，由用户选择
 *  - invokePay(code) 关闭 sheet 并 triggerPay
 *
 * 返回的对象需要绑定到 sheet 组件：
 *   <mb-pay-method-sheet
 *     :visible="sheetVisible"
 *     :methods="methods"
 *     :loading="loading"
 *     :amount="amount"
 *     @select="onSheetSelect"
 *     @close="sheetVisible = false"
 *   />
 */
export function usePayFlow() {
  const sheetVisible = ref(false)
  const methods = ref([])
  const loading = ref(false)
  const pendingOrderId = ref(null)

  async function startPay(orderId) {
    if (!orderId) return null
    pendingOrderId.value = orderId

    loading.value = true
    try {
      methods.value = normalizeMethods(await getPayMethods())
    } catch (e) {
      loading.value = false
      uni.showToast({ title: e?.message || '获取支付方式失败', icon: 'none' })
      return { status: 'fail', message: e?.message || '获取支付方式失败' }
    }
    loading.value = false

    const list = Array.isArray(methods.value) ? methods.value.filter((item) => !item.disabled) : []
    if (list.length === 0) {
      uni.showToast({ title: '当前无可用支付方式', icon: 'none' })
      return { status: 'fail', message: '当前无可用支付方式' }
    }
    if (list.length === 1 && methods.value.length === 1) {
      return await triggerPay(orderId, list[0].code)
    }
    sheetVisible.value = true
    return null
  }

  async function invokePay(payMethod) {
    sheetVisible.value = false
    if (!pendingOrderId.value) {
      return { status: 'fail', message: '订单信息缺失' }
    }
    return await triggerPay(pendingOrderId.value, payMethod)
  }

  function closeSheet() {
    sheetVisible.value = false
  }

  return { sheetVisible, methods, loading, startPay, invokePay, closeSheet }
}

function normalizeMethods(methods) {
  if (!Array.isArray(methods)) return []
  const platform = getPlatform()
  return methods
    .filter((item) => Number(item?.code) !== 9)
    .filter((item) => {
      const code = Number(item?.code)
      if (code !== 2) return true
      return platform === 'h5'
    })
    .map((item) => ({
      ...item,
      code: Number(item.code),
      disabled: Boolean(item.disabled),
    }))
}
