import { requestClient } from '#/api/request';

export namespace UpgradeApi {
  export type Action = 'rollback' | 'upgrade';
  export type Status =
    | 'awaiting_php_restart'
    | 'failed'
    | 'queued'
    | 'running'
    | 'succeeded';

  export interface RecordItem {
    action: Action;
    backup_path: string;
    created_at: number;
    error: string;
    finished_at: number;
    job_id: string;
    package_path: string;
    source_version: string;
    started_at: number;
    status: Status;
    target_version: string;
  }

  export interface RecordList {
    list: RecordItem[];
    total: number;
  }

  export interface JobCreated {
    expires_at: number;
    job_id: string;
    status: 'queued';
    status_url: string;
  }

  export interface CurrentRelease {
    notes: string[];
    released_at: string;
    version: string;
  }

  export interface Overview {
    current: CurrentRelease;
  }

  export interface ReleaseCandidate {
    channel: 'stable';
    from_version: string;
    package_kind: 'full' | 'patch';
    released_at?: string;
    summary: string;
    version: string;
  }

  export interface ReleaseCatalog {
    checked_at: number;
    current_version: string;
    releases: ReleaseCandidate[];
  }
}

export function getUpgradeOverviewApi() {
  return requestClient.get<UpgradeApi.Overview>('/system/upgrade/overview');
}

export function getUpgradeRecordsApi(params: { limit: number; page: number }) {
  return requestClient.get<UpgradeApi.RecordList>('/system/upgrade/records', {
    params,
  });
}

export function createUpgradeJobApi(
  action: UpgradeApi.Action,
  targetVersion = '',
) {
  return requestClient.post<UpgradeApi.JobCreated>('/system/upgrade/jobs', {
    action,
    target_version: targetVersion,
  });
}

export function getUpgradeReleaseCatalogApi() {
  return requestClient.get<UpgradeApi.ReleaseCatalog>(
    '/system/upgrade/releases',
  );
}

/**
 * 等待 systemd.path 启动当前任务的临时 Agent 页面。
 * 后台记录已经由 PHP 创建，因此页面未及时启动只影响实时查看，不影响长期历史。
 */
export async function waitForUpgradeStatusPage(
  jobId: string,
  wait: (milliseconds: number) => Promise<void> = (milliseconds) =>
    new Promise((resolve) => setTimeout(resolve, milliseconds)),
  attempts = 12,
): Promise<boolean> {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (attempt > 0) {
      await wait(Math.min(250 * 2 ** (attempt - 1), 2000));
    }
    try {
      const response = await fetch(
        `/upgrade/ready?job_id=${encodeURIComponent(jobId)}`,
        { cache: 'no-store', credentials: 'same-origin' },
      );
      if (!response.ok) continue;
      const body = (await response.json().catch(() => null)) as null | {
        status?: string;
      };
      if (body?.status === 'ready') return true;
    } catch {
      // systemd.path may still be starting the short-lived Agent process.
    }
  }
  return false;
}
