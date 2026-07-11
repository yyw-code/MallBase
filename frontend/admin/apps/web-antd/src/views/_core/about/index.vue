<script lang="ts" setup>
import { computed } from 'vue';

import { Page } from '@vben/common-ui';

import qqGroupImage from './assets/qq-group.jpg';

defineOptions({ name: 'About' });

interface AdminMetadata {
  buildTime?: string;
  dependencies?: Record<string, string>;
  devDependencies?: Record<string, string>;
  license?: string;
  version?: string;
}

const metadata: AdminMetadata = __VBEN_ADMIN_METADATA__ || {};

const websiteUrl = 'https://platform.gosowong.cn/';
const repositoryUrl = 'https://gitee.com/gosowong/mall-base';
const docsUrl = `${repositoryUrl}/tree/main/docs`;
const frontendDocsUrl = `${repositoryUrl}/blob/main/frontend/admin/README.md`;
const qqGroupNumber = '958717939';
const wechatNumber = 'yyw1329847115';

const projectHighlights = [
  '面向中小型商城业务的基础底座',
  '后台 API、权限、上传、商品、订单等模块按三层结构组织',
  '适合二次开发、团队协作和长期维护',
];

const architectureItems = [
  {
    content: 'ThinkPHP 8 多应用模式，业务入口分为 admin、client、install。',
    title: '后端分层',
  },
  {
    content: 'Swoole HTTP 服务托管接口和 admin 静态资源，Service 保持无状态。',
    title: '运行模式',
  },
  {
    content: '后台菜单、权限编码和路由路径由后端统一维护。',
    title: '权限路由',
  },
  {
    content:
      '后台构建产物部署到 backend/public/admin，统一由后端服务对外提供。',
    title: '部署路径',
  },
];

const conventionItems = [
  'Controller -> Service -> Model',
  '先校验再事务',
  '分页 list/total 条件同源',
  '素材上传统一走后端接口',
  '公开文案保持专业、清晰、适合开源传播',
];

const stackItems = [
  { label: 'PHP >= 8.2', type: '后端' },
  { label: 'ThinkPHP 8', type: '后端' },
  { label: 'think-swoole', type: '运行时' },
  { label: 'MySQL 8+', type: '数据' },
  { label: 'Redis 6+', type: '缓存' },
  { label: 'Vue 3', type: '前端' },
  { label: 'Vite', type: '构建' },
  { label: 'Ant Design Vue', type: 'UI' },
  { label: 'Vben Admin 5', type: '后台基座' },
];

const dependencyNames = [
  'vue',
  'vite',
  'ant-design-vue',
  'pinia',
  'vue-router',
  'dayjs',
];

const keyDependencies = computed(() =>
  dependencyNames.map((name) => ({
    name,
    version:
      metadata.dependencies?.[name] || metadata.devDependencies?.[name] || '-',
  })),
);

const buildItems = computed(() => [
  {
    content: metadata.version || '-',
    title: '后台前端基座版本',
  },
  {
    content: metadata.buildTime || '-',
    title: '最近构建时间',
  },
  {
    content: metadata.license || 'MIT',
    title: '开源协议',
  },
]);
</script>

<template>
  <Page title="关于 MallBase">
    <template #description>
      <p class="max-w-4xl text-sm leading-6 text-muted-foreground">
        MallBase 是面向中小型商城业务的开源项目基础底座，当前后台基于 Vben Admin
        5 构建，并与 ThinkPHP 8 + Swoole 后端协同工作。
      </p>
    </template>

    <div class="space-y-4">
      <section class="card-box overflow-hidden p-6">
        <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <a-tag color="processing">Open Source</a-tag>
              <a-tag color="success">MIT License</a-tag>
              <a-tag>Admin / API / Deploy</a-tag>
            </div>
            <h2 class="mt-4 text-2xl font-semibold text-foreground">
              MallBase 商城业务基础底座
            </h2>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
              项目目标不是堆叠功能清单，而是提供结构稳定、边界清楚、便于扩展的商城型应用骨架，让后台管理、接口服务、部署路径和开发规范保持一致。
            </p>
            <div class="mt-5 flex flex-wrap gap-2">
              <a-button type="primary" :href="websiteUrl" target="_blank">
                访问官方网站
              </a-button>
              <a-button :href="docsUrl" target="_blank">
                查看项目文档
              </a-button>
              <a-button :href="repositoryUrl" target="_blank">
                查看代码仓库
              </a-button>
              <a-button :href="frontendDocsUrl" target="_blank">
                后台前端说明
              </a-button>
            </div>
          </div>

          <div class="rounded-md border border-border bg-muted/40 p-4">
            <h3 class="text-base font-medium text-foreground">项目定位</h3>
            <ul class="mt-3 space-y-3">
              <li
                v-for="item in projectHighlights"
                :key="item"
                class="flex gap-2 text-sm leading-6 text-muted-foreground"
              >
                <span
                  class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                ></span>
                <span>{{ item }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div
          v-for="item in architectureItems"
          :key="item.title"
          class="card-box p-5"
        >
          <div class="text-base font-medium text-foreground">
            {{ item.title }}
          </div>
          <p class="mt-2 text-sm leading-6 text-muted-foreground">
            {{ item.content }}
          </p>
        </div>
      </section>

      <section class="card-box p-5">
        <div class="grid items-center gap-6 lg:grid-cols-[1fr_320px]">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <a-tag color="blue">QQ群</a-tag>
              <a-tag color="green">微信</a-tag>
              <a-tag>交流与反馈</a-tag>
            </div>
            <h3 class="mt-4 text-xl font-semibold text-foreground">
              MallBase 交流与反馈
            </h3>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
              欢迎通过QQ群或微信反馈安装、部署、二次开发和使用过程中的问题，也可以参与功能讨论与项目共建。
            </p>
            <div class="mt-5 flex flex-wrap gap-3">
              <div
                class="inline-flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/30 px-4 py-3"
              >
                <span class="text-sm text-muted-foreground">QQ群号</span>
                <span class="font-mono text-lg font-semibold text-foreground">
                  {{ qqGroupNumber }}
                </span>
              </div>
              <div
                class="inline-flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/30 px-4 py-3"
              >
                <span class="text-sm text-muted-foreground">微信号</span>
                <span class="font-mono text-lg font-semibold text-foreground">
                  {{ wechatNumber }}
                </span>
              </div>
            </div>
          </div>

          <div
            class="flex justify-center rounded-md border border-border bg-muted/30 p-4"
          >
            <img
              :src="qqGroupImage"
              alt="MallBase QQ群二维码"
              class="max-h-[360px] w-full max-w-[260px] rounded-md object-contain"
            />
          </div>
        </div>
      </section>

      <section class="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
        <div class="card-box p-5">
          <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="m-0 text-lg font-semibold text-foreground">开发约定</h3>
            <a-tag>Project Rules</a-tag>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <div
              v-for="item in conventionItems"
              :key="item"
              class="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm"
            >
              {{ item }}
            </div>
          </div>
        </div>

        <div class="card-box p-5">
          <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="m-0 text-lg font-semibold text-foreground">技术组成</h3>
            <a-tag>Stack</a-tag>
          </div>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="item in stackItems"
              :key="item.label"
              class="rounded-md border border-border p-3"
            >
              <div class="text-xs text-muted-foreground">{{ item.type }}</div>
              <div class="mt-1 text-sm font-medium text-foreground">
                {{ item.label }}
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="grid gap-4 xl:grid-cols-[0.75fr_1.25fr]">
        <div class="card-box p-5">
          <h3 class="m-0 text-lg font-semibold text-foreground">当前构建</h3>
          <dl class="mt-4 space-y-3">
            <div
              v-for="item in buildItems"
              :key="item.title"
              class="flex justify-between gap-4 border-t border-border pt-3"
            >
              <dt class="text-sm text-muted-foreground">{{ item.title }}</dt>
              <dd class="break-all text-right text-sm text-foreground">
                {{ item.content }}
              </dd>
            </div>
          </dl>
        </div>

        <div class="card-box p-5">
          <h3 class="m-0 text-lg font-semibold text-foreground">
            关键前端依赖
          </h3>
          <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="item in keyDependencies"
              :key="item.name"
              class="rounded-md border border-border p-3"
            >
              <dt class="font-mono text-sm">{{ item.name }}</dt>
              <dd class="mt-1 break-all text-sm text-muted-foreground">
                {{ item.version }}
              </dd>
            </div>
          </dl>
        </div>
      </section>
    </div>
  </Page>
</template>
