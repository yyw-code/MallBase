import { defineStore } from 'pinia'
import { get, post } from '@/api/request'
import { notifyAuthCleared, rotateAuthSessionId } from '@/utils/auth'

const TOKEN_KEY = 'mb_access_token'
const REFRESH_KEY = 'mb_refresh_token'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: '',
    refreshToken: '',
    userInfo: null,
    isLoggedIn: false,
  }),
  actions: {
    restoreToken() {
      this.token = uni.getStorageSync(TOKEN_KEY) || ''
      this.refreshToken = uni.getStorageSync(REFRESH_KEY) || ''
      this.isLoggedIn = !!this.token
    },
    setToken(accessToken, refreshToken) {
      this.token = accessToken
      this.refreshToken = refreshToken
      this.isLoggedIn = true
      uni.setStorageSync(TOKEN_KEY, accessToken)
      uni.setStorageSync(REFRESH_KEY, refreshToken)
      rotateAuthSessionId()
    },
    clearAuth() {
      this.token = ''
      this.refreshToken = ''
      this.userInfo = null
      this.isLoggedIn = false
      uni.removeStorageSync(TOKEN_KEY)
      uni.removeStorageSync(REFRESH_KEY)
      notifyAuthCleared()
    },
    async fetchUserInfo() {
      if (!this.token) {
        this.restoreToken()
      }
      if (!this.token) {
        this.userInfo = null
        this.isLoggedIn = false
        return null
      }

      try {
        const data = await get('/client/api/user/my/info')
        this.userInfo = data
        this.isLoggedIn = true
        return data
      } catch (e) {
        if (!uni.getStorageSync(TOKEN_KEY)) {
          this.clearAuth()
        }
        throw e
      }
    },
    async logout() {
      try {
        await post('/client/api/user/my/logout')
      } catch (e) { /* ignore */ }
      this.clearAuth()
    }
  }
})
