import { router } from '#/router';

let redirecting = false;

export function handleMaintenanceResponse(data: any): boolean {
  if (data?.data?.reason !== 'SYSTEM_MAINTENANCE') return false;
  if (redirecting || router.currentRoute.value.name === 'Maintenance') {
    return true;
  }
  redirecting = true;
  void router.replace({ name: 'Maintenance' }).finally(() => {
    redirecting = false;
  });
  return true;
}
