<script lang="ts" setup>
defineOptions({ name: 'Maintenance' });

function openUpgradePage() {
  window.location.assign('/upgrade/');
}
</script>

<template>
  <main class="maintenance-page">
    <a-card class="maintenance-card" :bordered="true">
      <a-result
        status="warning"
        sub-title="普通后台功能已暂停。PHP 代码由你手动重新部署或重启，独立 Go 升级页面不会因此中断。"
        title="系统正在维护"
      >
        <template #extra>
          <a-button type="primary" @click="openUpgradePage">
            打开当前任务状态
          </a-button>
        </template>
      </a-result>

      <a-alert
        message="PHP 代码部署由管理员手动完成"
        description="升级或回滚完成后，Docker 部署请重新构建镜像并重建 Queue、Cron、HTTP 容器；非 Docker 部署请先重启 Queue/Cron，最后重启 HTTP。Go 程序不会执行 Docker、systemctl 或服务重启命令。"
        show-icon
        type="info"
      />
    </a-card>
  </main>
</template>

<style scoped>
.maintenance-page {
  display: grid;
  min-height: 100vh;
  padding: 32px 20px;
  background: hsl(var(--background));
  color: hsl(var(--foreground));
  place-items: center;
}

.maintenance-card {
  width: min(760px, 100%);
  border-color: hsl(var(--border));
  background: hsl(var(--card));
  color: hsl(var(--foreground));
}
</style>
