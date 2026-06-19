import { requestClient } from '#/api/request';

export namespace WorkspaceApi {
  export interface Shortcut {
    icon: string;
    path: string;
    title: string;
  }

  export interface Todo {
    action_text: string;
    count: number;
    description: string;
    key: string;
    level: 'danger' | 'info' | 'warning';
    link: string;
    query?: Record<string, number | string>;
    title: string;
  }
}

export async function getWorkspaceTodoPendingShipmentApi() {
  return requestClient.get<WorkspaceApi.Todo[]>(
    '/workspace/todos/pendingShipment',
  );
}

export async function getWorkspaceTodoRefundPendingApi() {
  return requestClient.get<WorkspaceApi.Todo[]>(
    '/workspace/todos/refundPending',
  );
}

export async function getWorkspaceTodoStockWarningApi() {
  return requestClient.get<WorkspaceApi.Todo[]>(
    '/workspace/todos/stockWarning',
  );
}

export async function getWorkspaceTodoLogisticsConfigApi() {
  return requestClient.get<WorkspaceApi.Todo[]>(
    '/workspace/todos/logisticsConfig',
  );
}

export async function getWorkspaceTodoSmsProviderConfigApi() {
  return requestClient.get<WorkspaceApi.Todo[]>(
    '/workspace/todos/smsProviderConfig',
  );
}

export async function getWorkspaceShortcutsApi() {
  return requestClient.get<WorkspaceApi.Shortcut[]>('/workspace/shortcuts');
}

export async function getWorkspaceMenuOptionsApi() {
  return requestClient.get<WorkspaceApi.Shortcut[]>('/workspace/menu-options');
}

export async function updateWorkspaceShortcutsApi(
  shortcuts: Array<{ path: string }> | string[],
) {
  return requestClient.put<WorkspaceApi.Shortcut[]>('/workspace/shortcuts', {
    shortcuts,
  });
}
