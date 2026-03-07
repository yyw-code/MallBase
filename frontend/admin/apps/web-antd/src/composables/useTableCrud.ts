import { message, Modal } from 'ant-design-vue';
import { reactive, ref } from 'vue';

/**
 * 通用表格 CRUD 操作 composable
 * @param api API 对象，包含 list、create、update、delete 等方法
 * @param options 配置选项
 */
export function useTableCrud<T, P>(
  api: {
    list: (params?: P) => Promise<{ list: T[]; total: number }>;
    create?: (data: any) => Promise<any>;
    update?: (id: number, data: any) => Promise<any>;
    delete?: (id: number) => Promise<any>;
    getInfo?: (id: number) => Promise<T>;
  },
  options?: {
    defaultPageSize?: number;
    immediateLoad?: boolean;
  },
) {
  // 表格数据
  const tableData = ref<T[]>([]);
  const loading = ref(false);

  // 分页配置
  const pagination = reactive({
    current: 1,
    pageSize: options?.defaultPageSize || 10,
    total: 0,
    showSizeChanger: true,
    showTotal: (total: number) => `共 ${total} 条`,
  });

  // 加载数据
  const loadData = async (params?: P) => {
    loading.value = true;
    try {
      const result = await api.list({
        page: pagination.current,
        limit: pagination.pageSize,
        ...params,
      });
      tableData.value = result.list;
      pagination.total = result.total;
    } catch (error) {
      console.error('加载数据失败:', error);
      message.error('加载数据失败');
    } finally {
      loading.value = false;
    }
  };

  // 刷新数据
  const refresh = () => {
    loadData();
  };

  // 删除
  const handleDelete = (record: T, titleField: string = 'name') => {
    if (!api.delete) return;

    const title = (record as any)[titleField] || '该记录';
    Modal.confirm({
      content: `确定要删除"${title}"吗？`,
      onOk: async () => {
        await api.delete((record as any).id);
        message.success('删除成功');
        loadData();
      },
    });
  };

  // 初始化加载数据
  if (options?.immediateLoad !== false) {
    loadData();
  }

  return {
    tableData,
    loading,
    pagination,
    loadData,
    refresh,
    handleDelete,
  };
}

/**
 * 通用表单弹窗 composable
 */
export function useFormModal<T>() {
  const modalVisible = ref(false);
  const modalTitle = ref('新增');
  const formData = ref<any>({});
  const formRef = ref();

  // 打开新增弹窗
  const openCreateModal = (initialData?: any) => {
    modalTitle.value = '新增';
    formData.value = initialData || {};
    modalVisible.value = true;
  };

  // 打开编辑弹窗
  const openEditModal = async (record: T, getInfoApi?: (id: number) => Promise<T>) => {
    modalTitle.value = '编辑';
    if (getInfoApi) {
      const detail = await getInfoApi((record as any).id);
      formData.value = detail;
    } else {
      formData.value = { ...record };
    }
    modalVisible.value = true;
  };

  // 关闭弹窗
  const closeModal = () => {
    modalVisible.value = false;
    formData.value = {};
  };

  // 提交表单
  const handleSubmit = async (
    api: {
      create?: (data: any) => Promise<any>;
      update?: (id: number, data: any) => Promise<any>;
    },
    onSuccess?: () => void,
  ) => {
    try {
      await formRef.value?.validate();

      if (modalTitle.value.includes('新增')) {
        if (api.create) {
          await api.create(formData.value);
          message.success('创建成功');
        }
      } else {
        if (api.update) {
          await api.update(formData.value.id || 0, formData.value);
          message.success('更新成功');
        }
      }

      closeModal();
      onSuccess?.();
    } catch (error) {
      console.error('提交失败:', error);
    }
  };

  return {
    modalVisible,
    modalTitle,
    formData,
    formRef,
    openCreateModal,
    openEditModal,
    closeModal,
    handleSubmit,
  };
}