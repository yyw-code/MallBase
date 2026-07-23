export function getUniWindowInfo() {
  try {
    if (typeof uni !== 'undefined' && typeof uni.getWindowInfo === 'function') {
      return uni.getWindowInfo() || {}
    }
  } catch {}
  return {}
}

export function getUniAppBaseInfo() {
  try {
    if (typeof uni !== 'undefined' && typeof uni.getAppBaseInfo === 'function') {
      return uni.getAppBaseInfo() || {}
    }
  } catch {}
  return {}
}
