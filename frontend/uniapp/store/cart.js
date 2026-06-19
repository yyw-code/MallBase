import { defineStore } from 'pinia'
import {
  getCartList,
  addToCart,
  updateCartItem,
  deleteCartItems,
  toggleCartSelected,
} from '@/api/order/cart'
import { multiplyPrice, sumPrices } from '@/utils/price'

export const useCartStore = defineStore('cart', {
  state: () => ({
    list: [],
    loading: false,
  }),

  getters: {
    count: (state) => state.list.length,

    selectedItems: (state) => state.list.filter((item) => item.selected),

    selectedCount: (state) => state.list.filter((item) => item.selected).length,

    allSelected: (state) => state.list.length > 0 && state.list.every((item) => item.selected),

    totalPrice(state) {
      const subtotals = state.list
        .filter((item) => item.selected)
        .map((item) => multiplyPrice(item.unit_price, item.quantity))
      return sumPrices(subtotals)
    },
  },

  actions: {
    async fetchList() {
      this.loading = true
      try {
        const data = await getCartList()
        this.list = Array.isArray(data?.list) ? data.list : (Array.isArray(data) ? data : [])
      } catch {
        this.list = []
      } finally {
        this.loading = false
      }
    },

    async add(skuId, quantity = 1) {
      await addToCart({ sku_id: skuId, quantity })
      await this.fetchList()
    },

    async updateQuantity(cartId, quantity) {
      await updateCartItem(cartId, { quantity })
      const item = this.list.find((i) => i.id === cartId)
      if (item) item.quantity = quantity
    },

    async remove(cartIds) {
      await deleteCartItems(cartIds)
      this.list = this.list.filter((i) => !cartIds.includes(i.id))
    },

    async toggleSelected(cartIds, selected) {
      await toggleCartSelected(cartIds, selected ? 1 : 0)
      this.list.forEach((item) => {
        if (cartIds.includes(item.id)) item.selected = selected
      })
    },

    async toggleAll(selected) {
      const ids = this.list.map((i) => i.id)
      if (ids.length === 0) return
      await toggleCartSelected(ids, selected ? 1 : 0)
      this.list.forEach((item) => { item.selected = selected })
    },
  },
})
