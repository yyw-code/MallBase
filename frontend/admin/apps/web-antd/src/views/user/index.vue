<script lang="ts" setup>
import type { DistributionApi } from '#/api/distribution';
import type { ClientUserApi, UserGroupApi, UserTagApi } from '#/api/user';

import { computed, h, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Modal, Switch, Tag } from 'ant-design-vue';

import {
  getDistributionLevelListApi,
  openDistributionDistributorApi,
} from '#/api/distribution';
import { getSettingConfigApi } from '#/api/setting';
import {
  adjustClientUserPointsApi,
  adjustClientUserWalletApi,
  deleteClientUserApi,
  exportClientUserCsvApi,
  getClientUserInfoApi,
  getClientUserListApi,
  getClientUserMemberLevelOptionsApi,
  getClientUserPointsLogsApi,
  getClientUserStatsApi,
  getClientUserWalletLogsApi,
  getUserGroupListApi,
  getUserTagListApi,
  resetClientUserPasswordApi,
  setClientUserMemberApi,
  updateClientUserStatusApi,
} from '#/api/user';
import { useTableCrud } from '#/composables/useTableCrud';
import { downloadBlob } from '#/utils/download';

import UserModal from './user-modal.vue';

defineOptions({ name: 'ClientUserManagement' });

const { hasAccessByCodes } = useAccess();

// ==================== 性别映射 ====================
const GENDER_MAP: Record<number, { color: string; label: string }> = {
  0: { label: '未知', color: 'default' },
  1: { label: '男', color: 'blue' },
  2: { label: '女', color: 'pink' },
};

// ==================== 注册类型映射 ====================
const REGISTER_TYPE_MAP: Record<string, { color: string; label: string }> = {
  mobile: { label: '手机', color: 'cyan' },
  email: { label: '邮箱', color: 'purple' },
};

// ==================== 分组和标签选项 ====================
const groupOptions = ref<UserGroupApi.GroupItem[]>([]);
const tagOptions = ref<UserTagApi.TagItem[]>([]);
const memberLevelOptions = ref<ClientUserApi.MemberLevelOption[]>([]);
const distributionLevelOptions = ref<DistributionApi.LevelItem[]>([]);
const pointsEnabled = ref(true);
const memberEnabled = ref(false);
const distributionEnabled = ref(true);

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData } = useTableCrud<
  ClientUserApi.UserItem,
  ClientUserApi.ListParams
>(
  {
    delete: deleteClientUserApi,
    list: getClientUserListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
  register_type: undefined as string | undefined,
  group_ids: [] as number[],
  tag_ids: [] as number[],
});
const activeStatusTab = ref('all');
const statsTabs = ref<ClientUserApi.StatsTab[]>([]);

const buildQuery = (): ClientUserApi.ListParams => ({ ...searchParams.value });

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
    register_type: undefined,
    group_ids: [],
    tag_ids: [],
  };
  activeStatusTab.value = 'all';
  pagination.current = 1;
  refreshData();
};

const submitSearch = () => {
  pagination.current = 1;
  refreshData();
};

const loadStats = async () => {
  const res = await getClientUserStatsApi(buildQuery());
  statsTabs.value = res?.tabs ?? [];
};

const refreshData = async () => {
  await Promise.all([loadData(buildQuery()), loadStats()]);
};

const handleStatusTabChange = (key: string) => {
  activeStatusTab.value = key;
  searchParams.value.status = key === 'all' ? undefined : Number(key);
  pagination.current = 1;
  refreshData();
};

const handleExport = async () => {
  try {
    const blob = await exportClientUserCsvApi(buildQuery());
    downloadBlob(blob, 'users.csv');
  } catch (error: any) {
    message.error(error?.message || '导出失败');
  }
};

/* ---------------- 弹窗 ---------------- */
const userModalVisible = ref(false);
const editingItem = ref<ClientUserApi.UserItem | null>(null);
const detailDrawerVisible = ref(false);
const detailLoading = ref(false);
const detailUser = ref<ClientUserApi.UserItem | null>(null);
const detailActiveTab = ref('overview');

const formatEmpty = (value?: null | number | string) =>
  value === undefined || value === null || value === '' ? '-' : value;

const formatUserLabel = (record?: ClientUserApi.UserItem | null) => {
  if (!record) return '';
  return (
    record.nickname || record.mobile || record.email || `用户 ${record.id}`
  );
};

const formatDistributionUserLabel = (
  record?: ClientUserApi.DistributionUserInfo | null,
) => {
  if (!record) return '-';
  return (
    record.nickname || record.mobile || record.email || `用户 ${record.id}`
  );
};

const formatGender = (value?: number) => {
  const config = GENDER_MAP[value || 0] || GENDER_MAP[0]!;
  return config.label;
};

const formatRegisterType = (value?: string) => {
  const config =
    REGISTER_TYPE_MAP[value || 'mobile'] || REGISTER_TYPE_MAP.mobile!;
  return config.label;
};

const formatUserRegion = (record?: ClientUserApi.UserItem | null) => {
  if (!record) return '-';
  return (
    [record.province, record.city, record.district]
      .filter(Boolean)
      .join(' / ') || '-'
  );
};

const detailTitle = computed(() =>
  detailUser.value
    ? `用户详情 - ${formatUserLabel(detailUser.value)}`
    : '用户详情',
);

const detailAvatarText = computed(() =>
  formatUserLabel(detailUser.value).slice(0, 1).toUpperCase(),
);

const canShowWalletLogTab = computed(() =>
  hasAccessByCodes(['SystemUserWalletLog']),
);

const canShowPointsLogTab = computed(
  () => pointsEnabled.value && hasAccessByCodes(['SystemUserPointsLog']),
);

const resetDetailLogs = () => {
  walletLogs.value = [];
  walletLogPagination.current = 1;
  walletLogPagination.total = 0;
  pointsLogs.value = [];
  pointsLogPagination.current = 1;
  pointsLogPagination.total = 0;
};

const reloadDetailUser = async () => {
  if (!detailUser.value) return;
  detailUser.value = await getClientUserInfoApi(detailUser.value.id);
};

const handleDetail = async (record: ClientUserApi.UserItem) => {
  detailUser.value = record;
  detailActiveTab.value = 'overview';
  resetDetailLogs();
  detailDrawerVisible.value = true;
  detailLoading.value = true;
  try {
    await reloadDetailUser();
  } catch (error) {
    console.error('获取用户详情失败:', error);
    message.error('获取用户详情失败');
  } finally {
    detailLoading.value = false;
  }
};

const handleCreate = () => {
  editingItem.value = null;
  userModalVisible.value = true;
};

const handleEdit = async (record: ClientUserApi.UserItem) => {
  try {
    const detail = await getClientUserInfoApi(record.id);
    editingItem.value = detail;
    userModalVisible.value = true;
  } catch (error) {
    console.error('获取用户详情失败:', error);
    message.error('获取用户详情失败');
  }
};

const onModalSuccess = async () => {
  await refreshData();
  if (detailDrawerVisible.value && detailUser.value) {
    await reloadDetailUser();
  }
};

/* ---------------- 余额记录 / 调整 ---------------- */
const walletLogLoading = ref(false);
const walletLogs = ref<ClientUserApi.WalletLogItem[]>([]);
const walletUser = ref<ClientUserApi.UserItem | null>(null);
const walletLogPagination = reactive({
  current: 1,
  pageSize: 10,
  total: 0,
  showSizeChanger: true,
});

const walletAdjustVisible = ref(false);
const walletAdjustSubmitting = ref(false);
const walletAdjustMaxAmount = 999_999.99;
const walletAdjustForm = ref({
  user_id: 0,
  direction: 'income' as 'expense' | 'income',
  amount: '',
  remark: '',
});

const walletLogColumns = [
  { title: '时间', dataIndex: 'create_time', width: 170 },
  {
    title: '方向',
    dataIndex: 'direction',
    width: 90,
    customRender: ({ record }: { record: ClientUserApi.WalletLogItem }) =>
      record.direction === 'income' ? '收入' : '支出',
  },
  {
    title: '金额',
    dataIndex: 'change_amount',
    width: 120,
    customRender: ({ record }: { record: ClientUserApi.WalletLogItem }) =>
      `${record.direction === 'income' ? '+' : '-'}¥${record.change_amount}`,
  },
  { title: '变动前', dataIndex: 'before_amount', width: 120 },
  { title: '变动后', dataIndex: 'after_amount', width: 120 },
  {
    title: '业务类型',
    dataIndex: 'biz_type_text',
    width: 130,
    customRender: ({ record }: { record: ClientUserApi.WalletLogItem }) =>
      record.biz_type_text || record.biz_type || '-',
  },
  { title: '业务单号', dataIndex: 'biz_id', width: 180, ellipsis: true },
  { title: '备注', dataIndex: 'remark', width: 220, ellipsis: true },
  { title: '操作人', dataIndex: 'operator_id', width: 100 },
];

const loadWalletLogs = async (record = walletUser.value) => {
  if (!record) return;
  walletLogLoading.value = true;
  try {
    const result = await getClientUserWalletLogsApi({
      user_id: record.id,
      page: walletLogPagination.current,
      limit: walletLogPagination.pageSize,
    });
    walletLogs.value = result.list;
    walletLogPagination.total = result.total;
  } finally {
    walletLogLoading.value = false;
  }
};

const showWalletLogs = async () => {
  if (!detailUser.value) return;
  walletUser.value = detailUser.value;
  walletLogPagination.current = 1;
  detailActiveTab.value = 'wallet';
  await loadWalletLogs(detailUser.value);
};

const handleWalletAdjust = (record: ClientUserApi.UserItem) => {
  walletUser.value = record;
  walletAdjustForm.value = {
    user_id: record.id,
    direction: 'income',
    amount: '',
    remark: '',
  };
  walletAdjustVisible.value = true;
};

const submitWalletAdjust = async () => {
  if (!walletAdjustForm.value.amount || !walletAdjustForm.value.remark) {
    message.warning('请填写金额和调整原因');
    return;
  }
  walletAdjustSubmitting.value = true;
  try {
    await adjustClientUserWalletApi(walletAdjustForm.value);
    message.success('余额调整成功');
    walletAdjustVisible.value = false;
    await refreshData();
    if (detailDrawerVisible.value && detailUser.value) {
      await reloadDetailUser();
      if (detailActiveTab.value === 'wallet') {
        walletUser.value = detailUser.value;
        await loadWalletLogs(detailUser.value);
      }
    }
  } finally {
    walletAdjustSubmitting.value = false;
  }
};

/* ---------------- 积分记录 / 调整 ---------------- */
const pointsLogLoading = ref(false);
const pointsLogs = ref<ClientUserApi.PointsLogItem[]>([]);
const pointsUser = ref<ClientUserApi.UserItem | null>(null);
const pointsLogPagination = reactive({
  current: 1,
  pageSize: 10,
  total: 0,
  showSizeChanger: true,
});

const pointsAdjustVisible = ref(false);
const pointsAdjustSubmitting = ref(false);
const pointsAdjustMax = 999_999;
const pointsAdjustForm = ref({
  user_id: 0,
  direction: 'income' as 'expense' | 'income',
  points: 0,
  remark: '',
});

const pointsLogColumns = [
  { title: '时间', dataIndex: 'create_time', width: 170 },
  {
    title: '方向',
    dataIndex: 'direction',
    width: 90,
    customRender: ({ record }: { record: ClientUserApi.PointsLogItem }) =>
      record.direction === 'income' ? '收入' : '支出',
  },
  {
    title: '积分',
    dataIndex: 'change_points',
    width: 110,
    customRender: ({ record }: { record: ClientUserApi.PointsLogItem }) =>
      `${record.direction === 'income' ? '+' : '-'}${record.change_points}`,
  },
  { title: '变动前', dataIndex: 'before_points', width: 110 },
  { title: '变动后', dataIndex: 'after_points', width: 110 },
  {
    title: '业务类型',
    dataIndex: 'biz_type_text',
    width: 130,
    customRender: ({ record }: { record: ClientUserApi.PointsLogItem }) =>
      record.biz_type_text || record.biz_type || '-',
  },
  { title: '业务单号', dataIndex: 'biz_id', width: 180, ellipsis: true },
  { title: '备注', dataIndex: 'remark', width: 220, ellipsis: true },
  { title: '操作人', dataIndex: 'operator_id', width: 100 },
];

const loadPointsLogs = async (record = pointsUser.value) => {
  if (!record) return;
  pointsLogLoading.value = true;
  try {
    const result = await getClientUserPointsLogsApi({
      user_id: record.id,
      page: pointsLogPagination.current,
      limit: pointsLogPagination.pageSize,
    });
    pointsLogs.value = result.list;
    pointsLogPagination.total = result.total;
  } finally {
    pointsLogLoading.value = false;
  }
};

const showPointsLogs = async () => {
  if (!detailUser.value) return;
  pointsUser.value = detailUser.value;
  pointsLogPagination.current = 1;
  detailActiveTab.value = 'points';
  await loadPointsLogs(detailUser.value);
};

const handleDetailTabChange = async (key: string) => {
  detailActiveTab.value = key;
  if (key === 'wallet') {
    await showWalletLogs();
  }
  if (key === 'points') {
    await showPointsLogs();
  }
};

const handlePointsAdjust = (record: ClientUserApi.UserItem) => {
  pointsUser.value = record;
  pointsAdjustForm.value = {
    user_id: record.id,
    direction: 'income',
    points: 0,
    remark: '',
  };
  pointsAdjustVisible.value = true;
};

const submitPointsAdjust = async () => {
  if (!pointsAdjustForm.value.points || !pointsAdjustForm.value.remark) {
    message.warning('请填写积分和调整原因');
    return;
  }
  pointsAdjustSubmitting.value = true;
  try {
    await adjustClientUserPointsApi(pointsAdjustForm.value);
    message.success('积分调整成功');
    pointsAdjustVisible.value = false;
    await refreshData();
    if (detailDrawerVisible.value && detailUser.value) {
      await reloadDetailUser();
      if (detailActiveTab.value === 'points') {
        pointsUser.value = detailUser.value;
        await loadPointsLogs(detailUser.value);
      }
    }
  } finally {
    pointsAdjustSubmitting.value = false;
  }
};

/* ---------------- 设置会员 ---------------- */
const memberSetVisible = ref(false);
const memberSetSubmitting = ref(false);
const memberSetUser = ref<ClientUserApi.UserItem | null>(null);
const memberSetForm = ref({
  level_id: undefined as number | undefined,
  locked: true,
  lock_until: undefined as string | undefined,
  remark: '',
});

/* ---------------- 设为分销员 ---------------- */
const distributorSetVisible = ref(false);
const distributorSetSubmitting = ref(false);
const distributorSetUser = ref<ClientUserApi.UserItem | null>(null);
const distributorSetForm = ref({
  level_id: undefined as number | undefined,
  remark: '',
});

async function loadDistributionLevelOptions() {
  if (
    !distributionEnabled.value ||
    !hasAccessByCodes(['SystemDistributionDistributorOpen'])
  ) {
    distributionLevelOptions.value = [];
    return;
  }

  try {
    const data = await getDistributionLevelListApi({
      limit: 100,
      page: 1,
      status: 1,
    });
    distributionLevelOptions.value = data.list || [];
  } catch (error) {
    console.error('加载分销等级选项失败:', error);
    distributionLevelOptions.value = [];
  }
}

const handleDistributorSet = (record: ClientUserApi.UserItem) => {
  if (!distributionEnabled.value || record.distribution?.enabled === false) {
    message.warning('分销功能未开启');
    return;
  }
  if (distributionLevelOptions.value.length === 0) {
    message.warning('暂无可用分销等级');
    return;
  }

  distributorSetUser.value = record;
  distributorSetForm.value = {
    level_id:
      record.distribution?.distributor?.level_id ||
      distributionLevelOptions.value[0]?.id,
    remark: '',
  };
  distributorSetVisible.value = true;
};

const submitDistributorSet = async () => {
  const user = distributorSetUser.value;
  const levelId = distributorSetForm.value.level_id;
  if (!user || !levelId) {
    message.warning('请选择分销等级');
    return;
  }

  distributorSetSubmitting.value = true;
  try {
    await openDistributionDistributorApi({
      level_id: levelId,
      remark: distributorSetForm.value.remark,
      user_id: user.id,
    });
    message.success('已设为分销员');
    distributorSetVisible.value = false;
    await refreshData();
    if (detailDrawerVisible.value && detailUser.value?.id === user.id) {
      await reloadDetailUser();
    }
  } finally {
    distributorSetSubmitting.value = false;
  }
};

const handleMemberSet = (record: ClientUserApi.UserItem) => {
  memberSetUser.value = record;
  memberSetForm.value = {
    level_id: record.member?.level_id || undefined,
    locked: record.member?.level_source === 'manual',
    lock_until: record.member?.level_lock_until || undefined,
    remark: '',
  };
  memberSetVisible.value = true;
};

const formatMemberLevelDiscount = (value: string) => {
  const percent = Number.parseFloat(value);
  if (!Number.isFinite(percent)) return value;

  return `${percent}%`;
};

const formatMemberLevelOptionLabel = (level: ClientUserApi.MemberLevelOption) =>
  `${level.name}（${level.growth_min}成长值 / 按原价${formatMemberLevelDiscount(
    level.discount_percent,
  )}）`;

const submitMemberSet = async () => {
  if (!memberSetUser.value || !memberSetForm.value.level_id) {
    message.warning('请选择会员等级');
    return;
  }
  if (!memberSetForm.value.remark.trim()) {
    message.warning('请填写调整原因');
    return;
  }

  memberSetSubmitting.value = true;
  try {
    await setClientUserMemberApi(memberSetUser.value.id, {
      level_id: memberSetForm.value.level_id,
      locked: memberSetForm.value.locked,
      lock_until: memberSetForm.value.locked
        ? memberSetForm.value.lock_until
        : undefined,
      remark: memberSetForm.value.remark.trim(),
    });
    message.success('会员等级设置成功');
    memberSetVisible.value = false;
    await refreshData();
    if (detailDrawerVisible.value && detailUser.value) {
      await reloadDetailUser();
    }
  } finally {
    memberSetSubmitting.value = false;
  }
};

/* ---------------- 重置密码 ---------------- */
const handleResetPassword = (record: ClientUserApi.UserItem) => {
  Modal.confirm({
    title: '重置密码',
    content: `确定要重置用户「${record.nickname || record.mobile || record.email}」的密码为 123456 吗？`,
    onOk: async () => {
      // 生成默认密码 123456
      await resetClientUserPasswordApi(record.id, '123456');
      message.success('密码已重置为：123456');
    },
  });
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: ClientUserApi.UserItem,
  checked: boolean | number | string,
) => {
  try {
    await updateClientUserStatusApi(record.id, {
      status: checked === true ? 1 : 0,
    });
    message.success('状态更新成功');
    await refreshData();
  } catch {
    // 失败后刷新列表恢复状态
    await refreshData();
  }
};

const handleDelete = (record: ClientUserApi.UserItem) => {
  const title = record.nickname || record.mobile || record.email || '该用户';
  Modal.confirm({
    content: `确定要删除"${title}"吗？`,
    onOk: async () => {
      await deleteClientUserApi(record.id);
      message.success('删除成功');
      await refreshData();
    },
  });
};

/* ---------------- 表格列 ---------------- */
const baseColumns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  {
    title: '头像',
    width: 80,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!record.avatar) return '-';
      return h('img', {
        src: record.avatar_full_url || record.avatar,
        class: 'w-8 h-8 rounded-full object-cover',
        alt: 'avatar',
      });
    },
  },
  { title: '昵称', dataIndex: 'nickname', width: 120, ellipsis: true },
  { title: '手机号', dataIndex: 'mobile', width: 130 },
  {
    title: '余额',
    dataIndex: 'wallet',
    width: 120,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) =>
      `¥${record.wallet?.balance || '0.00'}`,
  },
  {
    title: '积分',
    dataIndex: 'points',
    width: 110,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) =>
      record.points?.balance_points ?? 0,
  },
  {
    title: '会员等级',
    dataIndex: 'member',
    width: 150,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      const levelName = record.member?.level_name || '-';
      if (record.member?.level_source !== 'manual') return levelName;

      return h('div', { class: 'flex flex-col gap-1' }, [
        h('span', levelName),
        h(Tag, { color: 'blue' }, () => '手动'),
      ]);
    },
  },
  {
    title: '成长值',
    dataIndex: 'member_growth',
    width: 100,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) =>
      record.member?.growth_value ?? 0,
  },
  { title: '邮箱', dataIndex: 'email', width: 180, ellipsis: true },
  {
    title: '注册方式',
    dataIndex: 'register_type',
    width: 90,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      const config =
        REGISTER_TYPE_MAP[record.register_type || 'mobile'] ||
        REGISTER_TYPE_MAP.mobile!;
      return h(
        'span',
        {
          class: `ant-tag ant-tag-${config.color}`,
        },
        config.label,
      );
    },
  },
  {
    title: '性别',
    dataIndex: 'gender',
    width: 70,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      const config = GENDER_MAP[record.gender || 0] || GENDER_MAP[0]!;
      return h(
        'span',
        {
          class: `ant-tag ant-tag-${config.color}`,
        },
        config.label,
      );
    },
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!hasAccessByCodes(['SystemUserUpdateStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean | number | string) =>
          handleStatusChange(record, checked),
      });
    },
  },
  {
    title: '分组',
    dataIndex: 'groups',
    width: 150,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!record.groups || record.groups.length === 0) return '-';

      return h(
        'div',
        { class: 'flex flex-wrap gap-1' },
        record.groups.map((group: UserGroupApi.GroupItem) =>
          h(Tag, { color: group.color || 'default' }, () => group.name),
        ),
      );
    },
  },
  {
    title: '标签',
    dataIndex: 'tags',
    width: 150,
    customRender: ({ record }: { record: ClientUserApi.UserItem }) => {
      if (!record.tags || record.tags.length === 0) return '-';

      return h(
        'div',
        { class: 'flex flex-wrap gap-1' },
        record.tags.map((tag: UserTagApi.TagItem) =>
          h(Tag, { color: tag.color || 'default' }, () => tag.name),
        ),
      );
    },
  },
  {
    title: '最后登录',
    dataIndex: 'last_login_time',
    width: 160,
    ellipsis: true,
  },
  { title: '注册时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', fixed: 'right', key: 'action', width: 200 },
];

const columns = computed(() =>
  baseColumns.filter((column) => {
    const key = String((column as any).dataIndex || (column as any).key || '');
    if (key === 'points') return pointsEnabled.value;
    if (key === 'member' || key === 'member_growth') return memberEnabled.value;
    return true;
  }),
);

const tableScrollX = computed(() =>
  columns.value.reduce(
    (total, column) =>
      total + Number((column as { width?: number | string }).width || 0),
    0,
  ),
);

function settingSwitchEnabled(value: unknown, fallback = true) {
  if (value === undefined || value === null || value === '') return fallback;
  return ['1', 'on', 'true'].includes(String(value).toLowerCase());
}

function settingItems(config: any) {
  return [
    ...(Array.isArray(config?.settings) ? config.settings : []),
    ...(config?.tabs || []).flatMap((tab: any) => tab.settings || []),
  ];
}

async function loadMarketingConfig() {
  const [pointsResult, memberResult, distributionResult] =
    await Promise.allSettled([
      getSettingConfigApi('PointsConfig'),
      getSettingConfigApi('MemberConfig'),
      getSettingConfigApi('DistributionConfig'),
    ]);

  if (pointsResult.status === 'fulfilled') {
    const pointsSwitch = settingItems(pointsResult.value).find(
      (item) => item.code === 'points_enabled',
    );
    pointsEnabled.value = settingSwitchEnabled(pointsSwitch?.value, true);
  } else {
    pointsEnabled.value = true;
  }

  if (memberResult.status === 'fulfilled') {
    const memberSwitch = settingItems(memberResult.value).find(
      (item) => item.code === 'member_enabled',
    );
    memberEnabled.value = settingSwitchEnabled(memberSwitch?.value, false);
  } else {
    memberEnabled.value = false;
  }

  if (distributionResult.status === 'fulfilled') {
    const distributionSwitch = settingItems(distributionResult.value).find(
      (item) => item.code === 'distribution_enabled',
    );
    distributionEnabled.value = settingSwitchEnabled(
      distributionSwitch?.value,
      true,
    );
  } else {
    distributionEnabled.value = true;
  }
}

async function loadMemberLevelOptions() {
  if (!memberEnabled.value) {
    memberLevelOptions.value = [];
    return;
  }

  try {
    memberLevelOptions.value = await getClientUserMemberLevelOptionsApi();
  } catch (error) {
    console.error('加载会员等级选项失败:', error);
    memberLevelOptions.value = [];
  }
}

/* ---------------- 初始化 ---------------- */
onMounted(async () => {
  try {
    await loadMarketingConfig();
    const [groups, tags] = await Promise.all([
      getUserGroupListApi({ status: 1, limit: 100 }),
      getUserTagListApi({ status: 1, limit: 100 }),
    ]);
    groupOptions.value = groups.list;
    tagOptions.value = tags.list;
    await Promise.all([
      loadMemberLevelOptions(),
      loadDistributionLevelOptions(),
    ]);
  } catch (error) {
    console.error('加载分组和标签失败:', error);
  }

  refreshData();
});
</script>

<template>
  <div class="user-page p-4">
    <div class="user-header">
      <div>
        <h2 class="user-title">用户列表</h2>
      </div>
      <div class="user-header-actions">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemUserCreate'"
        >
          新增用户
        </a-button>
        <a-button @click="refreshData">刷新</a-button>
        <a-button v-access:code="'SystemUserExport'" @click="handleExport">
          导出
        </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="user-filter-panel">
      <a-form>
        <div
          class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
        >
          <div>
            <a-form-item class="mb-0" label="关键词">
              <a-input
                v-model:value="searchParams.keyword"
                placeholder="手机号/邮箱/昵称"
                allow-clear
                class="w-full"
                @press-enter="submitSearch"
              />
            </a-form-item>
          </div>
          <div>
            <a-form-item class="mb-0" label="注册方式">
              <a-select
                v-model:value="searchParams.register_type"
                placeholder="请选择"
                allow-clear
                class="w-full"
              >
                <a-select-option value="mobile">手机</a-select-option>
                <a-select-option value="email">邮箱</a-select-option>
              </a-select>
            </a-form-item>
          </div>
          <div>
            <a-form-item class="mb-0" label="分组">
              <a-select
                v-model:value="searchParams.group_ids"
                mode="multiple"
                placeholder="请选择分组"
                allow-clear
                class="w-full"
                :options="
                  groupOptions.map((g) => ({ label: g.name, value: g.id }))
                "
              />
            </a-form-item>
          </div>
          <div>
            <a-form-item class="mb-0" label="标签">
              <a-select
                v-model:value="searchParams.tag_ids"
                mode="multiple"
                placeholder="请选择标签"
                allow-clear
                class="w-full"
                :options="
                  tagOptions.map((t) => ({ label: t.name, value: t.id }))
                "
              />
            </a-form-item>
          </div>
        </div>
        <div class="mt-3 flex justify-end gap-2">
          <a-button type="primary" @click="submitSearch">搜索</a-button>
          <a-button @click="resetSearch">重置</a-button>
        </div>
      </a-form>
    </div>

    <div class="user-table-panel">
      <a-tabs
        :active-key="activeStatusTab"
        class="user-status-tabs"
        size="small"
        @change="handleStatusTabChange"
      >
        <a-tab-pane v-for="tab in statsTabs" :key="tab.key">
          <template #tab>
            <span>{{ tab.label }} {{ tab.count }}</span>
          </template>
        </a-tab-pane>
      </a-tabs>

      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        :scroll="{ x: tableScrollX }"
        row-key="id"
        @change="
          (newPagination: any) => {
            pagination.current = newPagination.current;
            pagination.pageSize = newPagination.pageSize;
            loadData(buildQuery());
          }
        "
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space wrap>
              <a-button
                type="link"
                size="small"
                @click="handleDetail(record)"
                v-access:code="'SystemUserInfo'"
              >
                详情
              </a-button>
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemUserUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record)"
                v-access:code="'SystemUserDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 用户表单弹窗 -->
    <UserModal
      v-model:visible="userModalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />

    <a-drawer
      v-model:open="detailDrawerVisible"
      :title="detailTitle"
      width="880"
      destroy-on-close
    >
      <a-spin :spinning="detailLoading">
        <template v-if="detailUser">
          <div class="user-detail">
            <div class="user-detail__profile">
              <a-avatar
                :size="48"
                :src="detailUser.avatar_full_url || detailUser.avatar"
              >
                {{ detailAvatarText }}
              </a-avatar>
              <div class="user-detail__profile-main">
                <div class="user-detail__name">
                  {{ formatUserLabel(detailUser) }}
                </div>
                <div class="user-detail__meta">ID: {{ detailUser.id }}</div>
              </div>
              <a-tag :color="detailUser.status === 1 ? 'green' : 'red'">
                {{ detailUser.status === 1 ? '启用' : '禁用' }}
              </a-tag>
            </div>

            <div class="user-detail__section">
              <div class="user-detail__section-title">操作</div>
              <a-space wrap>
                <a-button
                  @click="handleWalletAdjust(detailUser)"
                  v-access:code="'SystemUserWalletAdjust'"
                >
                  调整余额
                </a-button>
                <a-button
                  v-if="pointsEnabled"
                  @click="handlePointsAdjust(detailUser)"
                  v-access:code="'SystemUserPointsAdjust'"
                >
                  调整积分
                </a-button>
                <a-button
                  v-if="memberEnabled"
                  @click="handleMemberSet(detailUser)"
                  v-access:code="'SystemUserSetMember'"
                >
                  设置会员
                </a-button>
                <a-button
                  v-if="
                    distributionEnabled &&
                    detailUser.distribution?.enabled !== false
                  "
                  @click="handleDistributorSet(detailUser)"
                  v-access:code="'SystemDistributionDistributorOpen'"
                >
                  {{
                    detailUser.distribution?.is_distributor
                      ? '调整分销员'
                      : '设为分销员'
                  }}
                </a-button>
                <a-button
                  danger
                  @click="handleResetPassword(detailUser)"
                  v-access:code="'SystemUserResetPassword'"
                >
                  重置密码
                </a-button>
              </a-space>
            </div>

            <a-tabs
              :active-key="detailActiveTab"
              @change="handleDetailTabChange"
            >
              <a-tab-pane key="overview" tab="概览">
                <div class="user-detail__section">
                  <div class="user-detail__section-title">基础信息</div>
                  <a-descriptions bordered size="small" :column="2">
                    <a-descriptions-item label="用户ID">
                      {{ detailUser.id }}
                    </a-descriptions-item>
                    <a-descriptions-item label="状态">
                      <a-tag :color="detailUser.status === 1 ? 'green' : 'red'">
                        {{ detailUser.status === 1 ? '启用' : '禁用' }}
                      </a-tag>
                    </a-descriptions-item>
                    <a-descriptions-item label="昵称">
                      {{ formatEmpty(detailUser.nickname) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="真实姓名">
                      {{ formatEmpty(detailUser.real_name) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="手机号">
                      {{ formatEmpty(detailUser.mobile) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="邮箱">
                      {{ formatEmpty(detailUser.email) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="性别">
                      {{ formatGender(detailUser.gender) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="注册方式">
                      {{ formatRegisterType(detailUser.register_type) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="手机认证">
                      <a-tag
                        :color="
                          detailUser.mobile_verified === 1 ? 'green' : 'default'
                        "
                      >
                        {{
                          detailUser.mobile_verified === 1 ? '已认证' : '未认证'
                        }}
                      </a-tag>
                    </a-descriptions-item>
                    <a-descriptions-item label="生日">
                      {{ formatEmpty(detailUser.birthday) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="所在地区" :span="2">
                      {{ formatUserRegion(detailUser) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="注册IP">
                      {{ formatEmpty(detailUser.register_ip) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="最后登录IP">
                      {{ formatEmpty(detailUser.last_login_ip) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="注册时间">
                      {{ formatEmpty(detailUser.create_time) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="最后登录">
                      {{ formatEmpty(detailUser.last_login_time) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="更新时间" :span="2">
                      {{ formatEmpty(detailUser.update_time) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="个人简介" :span="2">
                      {{ formatEmpty(detailUser.bio) }}
                    </a-descriptions-item>
                    <a-descriptions-item label="备注" :span="2">
                      {{ formatEmpty(detailUser.remark) }}
                    </a-descriptions-item>
                  </a-descriptions>
                </div>

                <div class="user-detail__section">
                  <div class="user-detail__section-title">资产与会员</div>
                  <a-descriptions bordered size="small" :column="2">
                    <a-descriptions-item label="余额">
                      ¥{{ detailUser.wallet?.balance || '0.00' }}
                    </a-descriptions-item>
                    <a-descriptions-item label="冻结余额">
                      ¥{{ detailUser.wallet?.frozen_amount || '0.00' }}
                    </a-descriptions-item>
                    <a-descriptions-item v-if="pointsEnabled" label="可用积分">
                      {{ detailUser.points?.balance_points ?? 0 }}
                    </a-descriptions-item>
                    <a-descriptions-item v-if="pointsEnabled" label="累计获取">
                      {{ detailUser.points?.total_income_points ?? 0 }}
                    </a-descriptions-item>
                    <a-descriptions-item
                      v-if="pointsEnabled"
                      label="累计消耗"
                      :span="2"
                    >
                      {{ detailUser.points?.total_expense_points ?? 0 }}
                    </a-descriptions-item>
                    <a-descriptions-item v-if="memberEnabled" label="会员等级">
                      {{ detailUser.member?.level_name || '-' }}
                    </a-descriptions-item>
                    <a-descriptions-item v-if="memberEnabled" label="成长值">
                      {{ detailUser.member?.growth_value ?? 0 }}
                    </a-descriptions-item>
                    <a-descriptions-item
                      v-if="memberEnabled"
                      label="累计成长值"
                    >
                      {{ detailUser.member?.total_growth_value ?? 0 }}
                    </a-descriptions-item>
                    <a-descriptions-item v-if="memberEnabled" label="等级来源">
                      <a-tag
                        :color="
                          detailUser.member?.level_source === 'manual'
                            ? 'blue'
                            : 'default'
                        "
                      >
                        {{
                          detailUser.member?.level_source === 'manual'
                            ? '手动'
                            : '自动'
                        }}
                      </a-tag>
                    </a-descriptions-item>
                    <a-descriptions-item
                      v-if="memberEnabled"
                      label="锁定到期"
                      :span="2"
                    >
                      {{ formatEmpty(detailUser.member?.level_lock_until) }}
                    </a-descriptions-item>
                    <a-descriptions-item
                      v-if="memberEnabled"
                      label="等级备注"
                      :span="2"
                    >
                      {{ formatEmpty(detailUser.member?.level_remark) }}
                    </a-descriptions-item>
                  </a-descriptions>
                </div>

                <div
                  v-if="detailUser.distribution"
                  class="user-detail__section"
                >
                  <div class="user-detail__section-title">分销信息</div>
                  <a-alert
                    v-if="!detailUser.distribution.enabled"
                    class="mb-3"
                    message="分销功能未开启"
                    show-icon
                    type="warning"
                  />
                  <a-descriptions bordered size="small" :column="2">
                    <a-descriptions-item label="分销员状态">
                      <a-tag
                        :color="
                          detailUser.distribution.distributor?.status === 1
                            ? 'green'
                            : 'default'
                        "
                      >
                        {{
                          detailUser.distribution.distributor?.status_text ||
                          '未开通'
                        }}
                      </a-tag>
                    </a-descriptions-item>
                    <a-descriptions-item label="绑定上级">
                      {{
                        formatDistributionUserLabel(
                          detailUser.distribution.relation?.parent_user,
                        )
                      }}
                    </a-descriptions-item>
                    <template v-if="detailUser.distribution.distributor">
                      <a-descriptions-item label="分销等级">
                        {{
                          formatEmpty(
                            detailUser.distribution.distributor.level_name,
                          )
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="邀请码">
                        {{
                          formatEmpty(
                            detailUser.distribution.distributor.invite_code,
                          )
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="开通来源">
                        {{
                          formatEmpty(
                            detailUser.distribution.distributor
                              .open_source_text,
                          )
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="开通时间">
                        {{
                          formatEmpty(
                            detailUser.distribution.distributor.opened_at,
                          )
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="可提现佣金">
                        ¥{{
                          detailUser.distribution.distributor
                            .available_commission
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="冻结佣金">
                        ¥{{
                          detailUser.distribution.distributor.frozen_commission
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="提现中">
                        ¥{{
                          detailUser.distribution.distributor.pending_withdraw
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="已提现">
                        ¥{{
                          detailUser.distribution.distributor
                            .withdrawn_commission
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="累计净佣金">
                        ¥{{
                          detailUser.distribution.distributor.total_commission
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="待扣回">
                        ¥{{
                          detailUser.distribution.distributor.debt_commission
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="团队人数">
                        {{
                          detailUser.distribution.distributor.direct_user_count
                        }}
                        /
                        {{
                          detailUser.distribution.distributor
                            .indirect_user_count
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="计佣订单">
                        {{ detailUser.distribution.distributor.order_count }}
                      </a-descriptions-item>
                    </template>
                    <template v-if="detailUser.distribution.relation">
                      <a-descriptions-item label="绑定来源">
                        {{
                          formatEmpty(
                            detailUser.distribution.relation.source_text,
                          )
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="关系状态">
                        <a-tag
                          :color="
                            detailUser.distribution.relation.is_valid
                              ? 'green'
                              : 'red'
                          "
                        >
                          {{
                            detailUser.distribution.relation.is_valid
                              ? '有效'
                              : '已过期'
                          }}
                        </a-tag>
                      </a-descriptions-item>
                      <a-descriptions-item label="绑定时间">
                        {{
                          formatEmpty(
                            detailUser.distribution.relation.create_time,
                          )
                        }}
                      </a-descriptions-item>
                      <a-descriptions-item label="有效期">
                        {{
                          formatEmpty(
                            detailUser.distribution.relation.expire_time,
                          )
                        }}
                      </a-descriptions-item>
                    </template>
                  </a-descriptions>
                </div>

                <div class="user-detail__section">
                  <div class="user-detail__section-title">分组与标签</div>
                  <a-descriptions bordered size="small" :column="1">
                    <a-descriptions-item label="分组">
                      <a-space v-if="detailUser.groups?.length" wrap>
                        <a-tag
                          v-for="group in detailUser.groups"
                          :key="group.id"
                          :color="group.color || 'default'"
                        >
                          {{ group.name }}
                        </a-tag>
                      </a-space>
                      <span v-else>-</span>
                    </a-descriptions-item>
                    <a-descriptions-item label="标签">
                      <a-space v-if="detailUser.tags?.length" wrap>
                        <a-tag
                          v-for="tag in detailUser.tags"
                          :key="tag.id"
                          :color="tag.color || 'default'"
                        >
                          {{ tag.name }}
                        </a-tag>
                      </a-space>
                      <span v-else>-</span>
                    </a-descriptions-item>
                  </a-descriptions>
                </div>
              </a-tab-pane>

              <a-tab-pane
                v-if="canShowWalletLogTab"
                key="wallet"
                tab="余额记录"
              >
                <div class="user-detail__tab-toolbar">
                  <a-button
                    @click="handleWalletAdjust(detailUser)"
                    v-access:code="'SystemUserWalletAdjust'"
                  >
                    调整余额
                  </a-button>
                </div>
                <a-table
                  :columns="walletLogColumns"
                  :data-source="walletLogs"
                  :loading="walletLogLoading"
                  :pagination="walletLogPagination"
                  :scroll="{ x: 1230 }"
                  row-key="id"
                  size="small"
                  @change="
                    (newPagination: any) => {
                      walletLogPagination.current = newPagination.current;
                      walletLogPagination.pageSize = newPagination.pageSize;
                      loadWalletLogs();
                    }
                  "
                />
              </a-tab-pane>

              <a-tab-pane
                v-if="canShowPointsLogTab"
                key="points"
                tab="积分记录"
              >
                <div class="user-detail__tab-toolbar">
                  <a-button
                    @click="handlePointsAdjust(detailUser)"
                    v-access:code="'SystemUserPointsAdjust'"
                  >
                    调整积分
                  </a-button>
                </div>
                <a-table
                  :columns="pointsLogColumns"
                  :data-source="pointsLogs"
                  :loading="pointsLogLoading"
                  :pagination="pointsLogPagination"
                  :scroll="{ x: 1220 }"
                  row-key="id"
                  size="small"
                  @change="
                    (newPagination: any) => {
                      pointsLogPagination.current = newPagination.current;
                      pointsLogPagination.pageSize = newPagination.pageSize;
                      loadPointsLogs();
                    }
                  "
                />
              </a-tab-pane>
            </a-tabs>
          </div>
        </template>
      </a-spin>
    </a-drawer>

    <a-modal
      v-model:open="walletAdjustVisible"
      title="调整余额"
      :confirm-loading="walletAdjustSubmitting"
      @ok="submitWalletAdjust"
    >
      <a-form
        :model="walletAdjustForm"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-form-item label="用户">
          <span>{{
            walletUser?.nickname || walletUser?.mobile || walletUser?.id
          }}</span>
        </a-form-item>
        <a-form-item label="调整方向" required>
          <a-radio-group v-model:value="walletAdjustForm.direction">
            <a-radio value="income">增加余额</a-radio>
            <a-radio value="expense">扣减余额</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="金额" required>
          <a-input-number
            v-model:value="walletAdjustForm.amount"
            :max="walletAdjustMaxAmount"
            :min="0.01"
            :precision="2"
            class="w-full"
            placeholder="单次最多 999999.99"
            string-mode
          />
          <div class="mt-1 text-xs text-gray-400">
            单次调整最多 999999.99 元，增加后余额也不能超过该上限
          </div>
        </a-form-item>
        <a-form-item label="调整原因" required>
          <a-textarea
            v-model:value="walletAdjustForm.remark"
            :maxlength="255"
            :rows="3"
            placeholder="请填写余额调整原因，便于后续审计"
            show-count
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="pointsAdjustVisible"
      title="调整积分"
      :confirm-loading="pointsAdjustSubmitting"
      @ok="submitPointsAdjust"
    >
      <a-form
        :model="pointsAdjustForm"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-form-item label="用户">
          <span>{{
            pointsUser?.nickname || pointsUser?.mobile || pointsUser?.id
          }}</span>
        </a-form-item>
        <a-form-item label="调整方向" required>
          <a-radio-group v-model:value="pointsAdjustForm.direction">
            <a-radio value="income">增加积分</a-radio>
            <a-radio value="expense">扣减积分</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="积分" required>
          <a-input-number
            v-model:value="pointsAdjustForm.points"
            :max="pointsAdjustMax"
            :min="1"
            :precision="0"
            class="w-full"
            placeholder="单次最多 999999"
          />
          <div class="mt-1 text-xs text-gray-400">
            扣减积分时不能超过用户当前可用积分
          </div>
        </a-form-item>
        <a-form-item label="调整原因" required>
          <a-textarea
            v-model:value="pointsAdjustForm.remark"
            :maxlength="255"
            :rows="3"
            placeholder="请填写积分调整原因，便于后续审计"
            show-count
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="memberSetVisible"
      title="设置会员"
      :confirm-loading="memberSetSubmitting"
      @ok="submitMemberSet"
    >
      <a-form
        :model="memberSetForm"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-form-item label="用户">
          <span>{{
            memberSetUser?.nickname ||
            memberSetUser?.mobile ||
            memberSetUser?.id
          }}</span>
        </a-form-item>
        <a-form-item label="当前成长值">
          <span>{{ memberSetUser?.member?.growth_value ?? 0 }}</span>
        </a-form-item>
        <a-form-item label="当前等级">
          <a-space>
            <span>{{ memberSetUser?.member?.level_name || '-' }}</span>
            <a-tag
              v-if="memberSetUser?.member?.level_source === 'manual'"
              color="blue"
            >
              手动
            </a-tag>
          </a-space>
        </a-form-item>
        <a-form-item label="会员等级" required>
          <a-select
            v-model:value="memberSetForm.level_id"
            allow-clear
            placeholder="请选择会员等级"
          >
            <a-select-option
              v-for="level in memberLevelOptions"
              :key="level.id"
              :value="level.id"
            >
              {{ formatMemberLevelOptionLabel(level) }}
            </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="锁定等级">
          <a-switch
            v-model:checked="memberSetForm.locked"
            checked-children="锁定"
            un-checked-children="自动"
          />
          <div class="mt-1 text-xs text-gray-400">
            锁定后订单完成只累计成长值，不自动覆盖当前等级
          </div>
        </a-form-item>
        <a-form-item v-if="memberSetForm.locked" label="锁定到期">
          <a-date-picker
            v-model:value="memberSetForm.lock_until"
            allow-clear
            class="w-full"
            show-time
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="不填表示永久锁定"
          />
        </a-form-item>
        <a-form-item label="调整原因" required>
          <a-textarea
            v-model:value="memberSetForm.remark"
            :maxlength="255"
            :rows="3"
            placeholder="请填写设置会员等级的原因"
            show-count
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-model:open="distributorSetVisible"
      :title="
        distributorSetUser?.distribution?.is_distributor
          ? '调整分销员'
          : '设为分销员'
      "
      :confirm-loading="distributorSetSubmitting"
      @ok="submitDistributorSet"
    >
      <a-form
        :model="distributorSetForm"
        :label-col="{ style: { width: '100px' } }"
        class="pt-4"
      >
        <a-form-item label="用户">
          <span>{{
            distributorSetUser?.nickname ||
            distributorSetUser?.mobile ||
            distributorSetUser?.id
          }}</span>
        </a-form-item>
        <a-form-item label="分销等级" required>
          <a-select
            v-model:value="distributorSetForm.level_id"
            placeholder="请选择分销等级"
          >
            <a-select-option
              v-for="level in distributionLevelOptions"
              :key="level.id"
              :value="level.id"
            >
              {{ level.name }}
            </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="备注">
          <a-textarea
            v-model:value="distributorSetForm.remark"
            :maxlength="255"
            :rows="3"
            allow-clear
            placeholder="可填写开通原因，便于后续审计"
            show-count
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<style scoped>
.user-page {
  min-height: 100%;
}

.user-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 12px;
}

.user-title {
  margin: 0;
  color: hsl(var(--foreground));
  font-size: 18px;
  font-weight: 600;
  line-height: 32px;
}

.user-header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.user-filter-panel,
.user-table-panel {
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.user-filter-panel {
  padding: 16px;
  margin-bottom: 12px;
}

.user-table-panel {
  overflow: hidden;
}

.user-status-tabs {
  padding: 0 16px;
  margin-bottom: 0;
}

.user-status-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 0;
}

.user-status-tabs :deep(.ant-tabs-tab) {
  padding: 14px 0 12px;
}

.user-table-panel :deep(.ant-table-wrapper) {
  border-top: 1px solid hsl(var(--border));
}

.user-detail {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.user-detail__profile {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-bottom: 16px;
  border-bottom: 1px solid hsl(var(--border));
}

.user-detail__profile-main {
  min-width: 0;
  flex: 1;
}

.user-detail__name {
  overflow: hidden;
  color: hsl(var(--foreground));
  font-size: 16px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-detail__meta {
  margin-top: 4px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.user-detail__section {
  margin-top: 16px;
}

.user-detail__section-title {
  margin-bottom: 8px;
  color: hsl(var(--foreground));
  font-weight: 600;
}

.user-detail__tab-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 12px;
}
</style>
