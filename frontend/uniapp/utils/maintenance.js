const MAINTENANCE_PATH = '/pages/system/maintenance'

let redirecting = false

export function handleMaintenanceBody(body) {
  if (body?.data?.reason !== 'SYSTEM_MAINTENANCE') return false
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
  if (current?.route === 'pages/system/maintenance' || redirecting) return true
  redirecting = true
  uni.reLaunch({
    url: MAINTENANCE_PATH,
    complete: () => {
      redirecting = false
    },
  })
  return true
}
