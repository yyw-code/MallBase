import { requestClient } from '#/api/request';

export namespace UpgradeApi {
  export type Action = 'rollback' | 'upgrade';
  export type Status =
    | 'applying'
    | 'awaiting_php_restart'
    | 'backing_up'
    | 'completed'
    | 'downloading'
    | 'draining'
    | 'failed'
    | 'preparing'
    | 'queued'
    | 'rolling_back'
    | 'running'
    | 'verifying';

  export interface RecordItem {
    action: Action;
    backup_path: string;
    created_at: number;
    error: string;
    finished_at: number;
    job_id: string;
    log_path: string;
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

  export interface EntryTicket {
    expires_at: number;
    upgrade_url: string;
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

export function createUpgradeEntryApi(targetVersion = '') {
  return requestClient.post<UpgradeApi.EntryTicket>(
    '/system/upgrade/session',
    targetVersion ? { target_version: targetVersion } : {},
  );
}

export function getUpgradeReleaseCatalogApi() {
  return requestClient.get<UpgradeApi.ReleaseCatalog>(
    '/system/upgrade/releases',
  );
}

export async function probeUpgradeAgentApi(): Promise<boolean> {
  try {
    const response = await fetch('/upgrade/health', {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) return false;
    const body = (await response.json().catch(() => null)) as null | {
      code?: number;
      status?: string;
    };

    return body?.status === 'ok' || body?.code === 200;
  } catch {
    return false;
  }
}
