import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    name: 'FreightTemplateManagement',
    path: '/settings/freight-template',
    component: () => import('#/views/settings/freight-template/index.vue'),
    meta: {
      icon: 'lucide:truck',
      title: '运费模板',
      order: 11,
    },
  },
];

export default routes;
