import type { RouteRecordRaw } from 'vue-router';

const routes: RouteRecordRaw[] = [
  {
    meta: {
      icon: 'lucide:settings',
      order: 0,
      title: '系统管理',
    },
    name: 'System',
    path: '/system',
    children: [
      {
        name: 'SystemAdmin',
        path: '/admin',
        component: () => import('#/views/system/admin/index.vue'),
        meta: {
          icon: 'lucide:users',
          title: '管理员管理',
        },
      },
      {
        name: 'SystemRole',
        path: '/role',
        component: () => import('#/views/system/role/index.vue'),
        meta: {
          icon: 'lucide:shield',
          title: '角色管理',
        },
      },
      {
        name: 'SystemPermission',
        path: '/permission',
        component: () => import('#/views/system/permission/index.vue'),
        meta: {
          icon: 'lucide:lock',
          title: '权限管理',
        },
      },
    ],
  },
];

export default routes;
