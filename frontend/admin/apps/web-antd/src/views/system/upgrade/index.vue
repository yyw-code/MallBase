<script lang="ts" setup>
import type { UpgradeApi } from '#/api/system/upgrade';

import { computed, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';
import { Page } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import { message } from 'ant-design-vue';

import {
  createUpgradeEntryApi,
  getUpgradeOverviewApi,
  getUpgradeRecordsApi,
  getUpgradeReleaseCatalogApi,
  probeUpgradeAgentApi,
} from '#/api/system/upgrade';

defineOptions({ name: 'SystemUpgrade' });

type CatalogStatus = 'error' | 'idle' | 'ready';

const { hasAccessByCodes } = useAccess();
const canEnter = computed(() =>
  hasAccessByCodes(['SystemUpgradeSessionCreate']),
);
const overview = ref<null | UpgradeApi.Overview>(null);
const overviewLoading = ref(false);
const overviewError = ref('');
const catalogStatus = ref<CatalogStatus>('idle');
const catalogError = ref('');
const releases = ref<UpgradeApi.ReleaseCandidate[]>([]);
const agentOnline = ref(false);
const lastCheckedAt = ref(0);
const recordsLoading = ref(false);
const enteringVersion = ref('');
const records = ref<UpgradeApi.RecordItem[]>([]);
const selectedRecord = ref<null | UpgradeApi.RecordItem>(null);
const selectedRelease = ref<null | UpgradeApi.ReleaseCandidate>(null);
const recordDrawerOpen = ref(false);
const releaseDrawerOpen = ref(false);
const pagination = reactive({ current: 1, pageSize: 20, total: 0 });

const currentRelease = computed(() => overview.value?.current ?? null);
const catalogReady = computed(() => catalogStatus.value === 'ready');
const highestRelease = computed(() =>
  catalogReady.value ? (releases.value[0] ?? null) : null,
);
const highestVersionText = computed(() =>
  highestRelease.value ? `v${highestRelease.value.version}` : '—',
);
const refreshing = computed(
  () => overviewLoading.value || recordsLoading.value,
);
const upgradeActionsDisabled = computed(
  () =>
    !agentOnline.value ||
    overviewLoading.value ||
    !catalogReady.value ||
    enteringVersion.value !== '',
);
const catalogStateText = computed(() => {
  switch (catalogStatus.value) {
    case 'error': {
      return '平台版本目录暂不可用';
    }
    default: {
      return '正在检查平台版本目录';
    }
  }
});
const catalogEmptyText = computed(() =>
  catalogReady.value ? '当前版本暂无平台直达升级包' : catalogStateText.value,
);
const catalogAlert = computed(() => {
  switch (catalogStatus.value) {
    case 'error': {
      return {
        description: catalogError.value,
        message: '平台可升级版本获取失败',
        type: 'error' as const,
      };
    }
    default: {
      return null;
    }
  }
});
const agentAlert = computed(() => {
  if (overviewLoading.value || agentOnline.value) return null;

  return {
    description: '版本目录仍可查看，启动后方可执行升级。',
    message: '升级执行服务未启动',
    type: 'info' as const,
  };
});

const releaseColumns = [
  { key: 'version', title: '版本', width: 180 },
  { key: 'type', title: '类型', width: 110 },
  {
    dataIndex: 'released_at',
    key: 'released_at',
    title: '发布时间',
    width: 160,
  },
  { dataIndex: 'summary', key: 'summary', title: '更新摘要' },
  { key: 'compatibility', title: '版本状态', width: 140 },
  { key: 'operation', title: '操作', width: 230 },
];

const recordColumns = [
  { dataIndex: 'action', key: 'action', title: '操作', width: 90 },
  { key: 'version', title: '版本', width: 180 },
  { dataIndex: 'status', key: 'status', title: '状态', width: 150 },
  { dataIndex: 'backup_path', key: 'backup_path', title: '备份位置' },
  { dataIndex: 'created_at', key: 'created_at', title: '创建时间', width: 180 },
  { key: 'operation', title: '详情', width: 80 },
];

const statusMeta: Record<string, { color: string; label: string }> = {
  applying: { color: 'processing', label: '正在应用代码' },
  awaiting_php_restart: { color: 'warning', label: '等待手动部署 PHP 代码' },
  backing_up: { color: 'processing', label: '正在备份' },
  completed: { color: 'success', label: '已完成' },
  downloading: { color: 'processing', label: '正在下载' },
  draining: { color: 'processing', label: '正在排空业务' },
  failed: { color: 'error', label: '失败' },
  preparing: { color: 'processing', label: '准备中' },
  queued: { color: 'default', label: '等待执行' },
  running: { color: 'processing', label: '执行中' },
  rolling_back: { color: 'processing', label: '正在恢复' },
  verifying: { color: 'processing', label: '正在校验' },
};

function actionLabel(action: UpgradeApi.Action): string {
  return action === 'rollback' ? '恢复' : '升级';
}

function statusLabel(status: UpgradeApi.Status): string {
  return statusMeta[status]?.label || status;
}

function statusColor(status: UpgradeApi.Status): string {
  return statusMeta[status]?.color || 'default';
}

function packageKindLabel(kind: UpgradeApi.ReleaseCandidate['package_kind']) {
  return kind === 'patch' ? '增量包' : '完整包';
}

function channelLabel(channel: UpgradeApi.ReleaseCandidate['channel']) {
  return channel === 'stable' ? '稳定版' : channel;
}

function formatTime(timestamp: number): string {
  if (!timestamp) return '-';
  return new Intl.DateTimeFormat('zh-CN', {
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(new Date(timestamp * 1000));
}

function formatReleaseDate(value?: string): string {
  if (!value) return '-';
  return value.slice(0, 10);
}

async function loadRecords() {
  recordsLoading.value = true;
  try {
    const result = await getUpgradeRecordsApi({
      limit: pagination.pageSize,
      page: pagination.current,
    });
    records.value = result.list || [];
    pagination.total = result.total || 0;
  } catch (error) {
    message.error(error instanceof Error ? error.message : '升级记录加载失败');
  } finally {
    recordsLoading.value = false;
  }
}

async function loadOverview() {
  overviewLoading.value = true;
  overviewError.value = '';
  catalogError.value = '';
  try {
    const [overviewResult, online] = await Promise.all([
      getUpgradeOverviewApi(),
      probeUpgradeAgentApi(),
    ]);
    overview.value = overviewResult;
    agentOnline.value = online;
    lastCheckedAt.value = Math.floor(Date.now() / 1000);
    try {
      const catalog = await getUpgradeReleaseCatalogApi();
      if (catalog.current_version !== overviewResult.current.version) {
        throw new Error('平台目录来源版本与后台运行版本不一致');
      }
      releases.value = catalog.releases || [];
      lastCheckedAt.value = catalog.checked_at || lastCheckedAt.value;
      catalogStatus.value = 'ready';
    } catch (error) {
      releases.value = [];
      catalogStatus.value = 'error';
      catalogError.value =
        error instanceof Error ? error.message : '请稍后重新检查';
    }
  } catch (error) {
    overviewError.value =
      error instanceof Error ? error.message : '当前运行版本读取失败';
    overview.value = null;
    releases.value = [];
    catalogStatus.value = 'error';
    catalogError.value = '当前版本不可用，已停止加载可升级版本';
  } finally {
    overviewLoading.value = false;
  }
}

async function refreshAll() {
  await Promise.all([loadOverview(), loadRecords()]);
}

async function enterUpgrade(targetVersion = '') {
  if (!canEnter.value) return;
  if (upgradeActionsDisabled.value) {
    if (!agentOnline.value) {
      message.warning('版本目录可查看，但需先启动 Go 升级程序才能执行');
    }
    return;
  }
  if (!targetVersion) {
    message.warning('请选择目标版本');
    return;
  }
  enteringVersion.value = targetVersion || '__default__';
  try {
    const entry = await createUpgradeEntryApi(targetVersion);
    window.location.assign(entry.upgrade_url);
  } catch (error) {
    message.error(error instanceof Error ? error.message : '无法进入升级页面');
  } finally {
    enteringVersion.value = '';
  }
}

function showRecord(record: UpgradeApi.RecordItem) {
  selectedRecord.value = record;
  recordDrawerOpen.value = true;
}

function showRelease(release: UpgradeApi.ReleaseCandidate) {
  selectedRelease.value = release;
  releaseDrawerOpen.value = true;
}

function handleTableChange(pager: { current?: number; pageSize?: number }) {
  pagination.current = pager.current || pagination.current;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  void loadRecords();
}

onMounted(refreshAll);
</script>

<template>
  <Page
    description="查看当前运行版本、平台直达版本和历史记录。最终兼容校验与升级执行将在独立安全页面中完成。"
    header-class="flex-wrap gap-3"
    title="系统升级"
  >
    <template #extra>
      <a-space wrap>
        <a-button
          :disabled="enteringVersion !== ''"
          :loading="refreshing"
          @click="refreshAll"
        >
          <template #icon>
            <IconifyIcon icon="lucide:refresh-cw" />
          </template>
          重新检查
        </a-button>
        <a-button
          v-access:code="'SystemUpgradeSessionCreate'"
          :disabled="upgradeActionsDisabled || !highestRelease"
          :loading="enteringVersion === highestRelease?.version"
          type="primary"
          @click="enterUpgrade(highestRelease?.version || '')"
        >
          <template #icon>
            <IconifyIcon icon="lucide:arrow-up" />
          </template>
          前往升级
        </a-button>
      </a-space>
    </template>

    <div class="space-y-4">
      <a-card class="theme-card" title="版本概览">
        <a-skeleton :loading="overviewLoading && !overview" active>
          <a-alert
            v-if="overviewError"
            :description="overviewError"
            class="mb-4"
            message="当前版本读取失败"
            show-icon
            type="error"
          />

          <div class="overview-grid">
            <section class="overview-column">
              <div class="overview-label">当前版本</div>
              <div class="version-heading">
                <span class="version-number">
                  {{ currentRelease ? `v${currentRelease.version}` : '--' }}
                </span>
                <a-tag v-if="currentRelease" color="success">当前运行</a-tag>
              </div>
              <div class="overview-muted">
                发布于 {{ formatReleaseDate(currentRelease?.released_at) }}
              </div>
              <ul v-if="currentRelease?.notes.length" class="release-notes">
                <li v-for="note in currentRelease.notes" :key="note">
                  {{ note }}
                </li>
              </ul>
            </section>

            <section class="overview-middle">
              <span class="overview-arrow-icon">
                <IconifyIcon icon="lucide:arrow-right" />
              </span>
              <div v-if="catalogReady" class="overview-relation">
                平台返回
                <strong>{{ releases.length }}</strong>
                个当前版本可直达目标
              </div>
              <div v-else class="overview-relation overview-muted">
                {{ catalogStateText }}
              </div>
            </section>

            <section class="overview-column">
              <div class="overview-label">最高平台版本</div>
              <template v-if="highestRelease">
                <div class="version-heading">
                  <span class="version-number">{{ highestVersionText }}</span>
                  <a-tag color="blue">最高版本</a-tag>
                </div>
                <div class="overview-muted">
                  发布于 {{ formatReleaseDate(highestRelease.released_at) }}
                </div>
                <p class="release-summary">{{ highestRelease.summary }}</p>
              </template>
              <template v-else>
                <div class="version-heading">
                  <span class="version-number">—</span>
                </div>
                <p class="overview-muted">
                  {{
                    catalogReady
                      ? '当前暂无更高的平台直达版本'
                      : catalogStateText
                  }}
                </p>
              </template>
            </section>
          </div>

          <div class="overview-status-bar">
            <a-space>
              <a-badge :status="agentOnline ? 'success' : 'default'" />
              <a-typography-text strong>
                {{
                  overviewLoading
                    ? '正在检查升级执行服务'
                    : agentOnline
                      ? '升级执行服务已就绪（Go）'
                      : '升级执行服务未启动（Go）'
                }}
              </a-typography-text>
            </a-space>
            <span class="overview-muted">
              最近检查：{{ formatTime(lastCheckedAt) }}
            </span>
          </div>
        </a-skeleton>
      </a-card>

      <a-card class="theme-card">
        <template #title>
          <div>
            <div>平台可升级版本</div>
            <div class="card-subtitle">
              以下版本均由平台提供从当前版本
              {{ currentRelease ? `v${currentRelease.version}` : '--' }}
              直达的已验证升级包；进入执行页后，Go 将按 Agent
              版本与存储布局再次确认兼容性。
            </div>
          </div>
        </template>

        <a-alert
          v-if="catalogAlert"
          :description="catalogAlert.description"
          :message="catalogAlert.message"
          class="mb-4"
          show-icon
          :type="catalogAlert.type"
        />

        <a-alert
          v-if="agentAlert"
          :description="agentAlert.description"
          :message="agentAlert.message"
          class="mb-4"
          show-icon
          :type="agentAlert.type"
        />

        <a-table
          row-key="version"
          :columns="releaseColumns"
          :data-source="releases"
          :loading="overviewLoading"
          :locale="{ emptyText: catalogEmptyText }"
          :pagination="false"
          :scroll="{ x: 980 }"
          size="small"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'version'">
              <a-space>
                <a-typography-text strong>
                  v{{ record.version }}
                </a-typography-text>
                <a-tag
                  v-if="record.version === highestRelease?.version"
                  color="blue"
                >
                  最高版本
                </a-tag>
              </a-space>
            </template>
            <template v-else-if="column.key === 'type'">
              {{ channelLabel(record.channel) }}
            </template>
            <template v-else-if="column.key === 'released_at'">
              {{ formatReleaseDate(record.released_at) }}
            </template>
            <template v-else-if="column.key === 'compatibility'">
              <a-tag color="blue">平台已发布</a-tag>
            </template>
            <template v-else-if="column.key === 'operation'">
              <a-space :size="4">
                <a-button type="link" @click="showRelease(record)">
                  查看说明
                </a-button>
                <a-divider type="vertical" />
                <a-button
                  v-access:code="'SystemUpgradeSessionCreate'"
                  :disabled="upgradeActionsDisabled"
                  :loading="enteringVersion === record.version"
                  type="link"
                  @click="enterUpgrade(record.version)"
                >
                  升级到此版本
                </a-button>
              </a-space>
            </template>
          </template>
        </a-table>
      </a-card>

      <a-card class="theme-card" title="升级记录">
        <template #extra>
          <a-button :loading="recordsLoading" @click="loadRecords">
            <template #icon>
              <IconifyIcon icon="lucide:refresh-cw" />
            </template>
            刷新
          </a-button>
        </template>
        <a-table
          row-key="job_id"
          :columns="recordColumns"
          :data-source="records"
          :loading="recordsLoading"
          :pagination="pagination"
          :scroll="{ x: 980 }"
          size="small"
          @change="handleTableChange"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'action'">
              {{ actionLabel(record.action) }}
            </template>
            <template v-else-if="column.key === 'version'">
              <a-typography-text code>
                {{ record.source_version || '-' }} →
                {{ record.target_version || '-' }}
              </a-typography-text>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="statusColor(record.status)">
                {{ statusLabel(record.status) }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'backup_path'">
              <a-typography-text v-if="record.backup_path" code copyable>
                {{ record.backup_path }}
              </a-typography-text>
              <span v-else>-</span>
            </template>
            <template v-else-if="column.key === 'created_at'">
              {{ formatTime(record.created_at) }}
            </template>
            <template v-else-if="column.key === 'operation'">
              <a-button type="link" @click="showRecord(record)">查看</a-button>
            </template>
          </template>
        </a-table>
      </a-card>
    </div>

    <a-drawer
      v-model:open="releaseDrawerOpen"
      title="版本说明"
      width="min(520px, 100vw)"
    >
      <a-descriptions v-if="selectedRelease" bordered :column="1" size="small">
        <a-descriptions-item label="版本">
          v{{ selectedRelease.version }}
        </a-descriptions-item>
        <a-descriptions-item label="来源版本">
          v{{ selectedRelease.from_version }}
        </a-descriptions-item>
        <a-descriptions-item label="升级包类型">
          {{ packageKindLabel(selectedRelease.package_kind) }}
        </a-descriptions-item>
        <a-descriptions-item label="发布类型">
          {{ channelLabel(selectedRelease.channel) }}
        </a-descriptions-item>
        <a-descriptions-item label="更新摘要">
          {{ selectedRelease.summary }}
        </a-descriptions-item>
        <a-descriptions-item label="兼容校验">
          进入升级执行页后，由 Go 按 Agent 版本与存储布局重新确认
        </a-descriptions-item>
      </a-descriptions>
    </a-drawer>

    <a-drawer
      v-model:open="recordDrawerOpen"
      title="升级记录详情"
      width="min(520px, 100vw)"
    >
      <a-descriptions v-if="selectedRecord" bordered :column="1" size="small">
        <a-descriptions-item label="任务 ID">
          <a-typography-text code copyable>
            {{ selectedRecord.job_id }}
          </a-typography-text>
        </a-descriptions-item>
        <a-descriptions-item label="操作">
          {{ actionLabel(selectedRecord.action) }}
        </a-descriptions-item>
        <a-descriptions-item label="版本">
          {{ selectedRecord.source_version || '-' }} →
          {{ selectedRecord.target_version || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="状态">
          {{ statusLabel(selectedRecord.status) }}
        </a-descriptions-item>
        <a-descriptions-item label="备份位置">
          {{ selectedRecord.backup_path || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="升级包位置">
          {{ selectedRecord.package_path || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="日志位置">
          {{ selectedRecord.log_path || '-' }}
        </a-descriptions-item>
        <a-descriptions-item label="开始时间">
          {{ formatTime(selectedRecord.started_at) }}
        </a-descriptions-item>
        <a-descriptions-item label="结束时间">
          {{ formatTime(selectedRecord.finished_at) }}
        </a-descriptions-item>
        <a-descriptions-item v-if="selectedRecord.error" label="失败原因">
          <a-typography-text type="danger">
            {{ selectedRecord.error }}
          </a-typography-text>
        </a-descriptions-item>
      </a-descriptions>
    </a-drawer>
  </Page>
</template>

<style scoped>
.theme-card {
  border-color: hsl(var(--border));
  background: hsl(var(--card));
  color: hsl(var(--foreground));
}

.card-subtitle {
  margin-top: 6px;
  color: hsl(var(--muted-foreground));
  font-size: 13px;
  font-weight: 400;
  line-height: 1.6;
}

.overview-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(220px, 0.72fr) minmax(0, 1fr);
  min-height: 165px;
}

.overview-column {
  min-width: 0;
  padding: 12px 7%;
}

.overview-middle {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  padding: 12px 24px;
  border-right: 1px solid hsl(var(--border));
  border-left: 1px solid hsl(var(--border));
  text-align: center;
}

.overview-label {
  margin-bottom: 10px;
  color: hsl(var(--foreground));
  font-size: 15px;
  font-weight: 600;
}

.version-heading {
  display: flex;
  min-height: 44px;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}

.version-number {
  color: hsl(var(--foreground));
  font-size: 30px;
  font-weight: 600;
  letter-spacing: -0.02em;
  line-height: 1.25;
}

.overview-muted {
  color: hsl(var(--muted-foreground));
  line-height: 1.7;
}

.release-notes {
  margin: 12px 0 0;
  padding-left: 20px;
  color: hsl(var(--muted-foreground));
  line-height: 1.8;
}

.release-summary {
  margin: 12px 0 0;
  color: hsl(var(--muted-foreground));
  line-height: 1.8;
}

.overview-arrow-icon {
  display: inline-flex;
  width: 64px;
  height: 44px;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: hsl(var(--primary) / 0.12);
  color: hsl(var(--primary));
  font-size: 28px;
}

.overview-relation {
  color: hsl(var(--foreground));
  font-size: 15px;
  line-height: 1.7;
}

.overview-relation strong {
  color: hsl(var(--primary));
  font-size: 22px;
  font-weight: 600;
}

.overview-status-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px 16px;
  margin-top: 16px;
  padding: 10px 16px;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  background: hsl(var(--muted) / 0.24);
}

@media (max-width: 1023px) {
  .overview-grid {
    grid-template-columns: 1fr;
  }

  .overview-column,
  .overview-middle {
    padding: 22px 8px;
  }

  .overview-middle {
    border-right: 0;
    border-left: 0;
    border-top: 1px solid hsl(var(--border));
    border-bottom: 1px solid hsl(var(--border));
  }

  .overview-arrow-icon {
    transform: rotate(90deg);
  }
}

@media (max-width: 767px) {
  .version-number {
    font-size: 26px;
  }

  .overview-status-bar {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
