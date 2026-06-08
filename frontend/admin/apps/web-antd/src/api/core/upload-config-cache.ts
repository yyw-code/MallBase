import type { UploadApi } from './upload';

import { getUploadConfigApi, getUploadOptionsApi } from './upload';

export type UploadType =
  | 'file'
  | 'files'
  | 'image'
  | 'images'
  | 'video'
  | 'videos';

interface CacheEntry {
  data: UploadApi.UploadRuleConfig;
  fetchedAt: number;
}

/** 上传规则缓存有效期：5 分钟内复用，过期后 SWR 后台静默刷新 */
const TTL_MS = 5 * 60 * 1000;

const cache = new Map<UploadType, CacheEntry>();
const inflight = new Map<UploadType, Promise<UploadApi.UploadRuleConfig>>();
let optionsCache: null | { data: UploadApi.UploadOptions; fetchedAt: number } =
  null;
let optionsInflight: null | Promise<UploadApi.UploadOptions> = null;

// ==================== 跨标签页广播 ====================

const CHANNEL_NAME = 'mall-base:upload-config';

interface InvalidateMessage {
  kind: 'invalidate';
  type?: UploadType;
}

const broadcastChannel: BroadcastChannel | null =
  typeof BroadcastChannel === 'undefined'
    ? null
    : new BroadcastChannel(CHANNEL_NAME);

if (broadcastChannel) {
  broadcastChannel.addEventListener('message', (event: MessageEvent) => {
    const data = event.data as InvalidateMessage | undefined;
    if (!data || data.kind !== 'invalidate') return;
    // 收到其他标签页的失效通知：只清本地缓存，不再广播，避免回环
    if (data.type) {
      cache.delete(data.type);
    } else {
      cache.clear();
      optionsCache = null;
    }
  });
}

/**
 * 获取上传规则配置（带缓存 + 在途去重 + SWR）。
 *
 * - 命中且未过期：直接返回缓存
 * - 命中但已过期：立即返回旧值，同时后台静默刷新（SWR）
 * - 未命中：发起请求；同 type 的并发调用共享同一 Promise（去重）
 *
 * 规则保存成功时主动调用 invalidateUploadConfig() 失效缓存，
 * 下一次 Upload 挂载即可拉到最新规则。
 */
export async function getUploadConfigCached(
  type: UploadType,
): Promise<UploadApi.UploadRuleConfig> {
  const now = Date.now();
  const hit = cache.get(type);
  const isFresh = hit && now - hit.fetchedAt < TTL_MS;

  if (isFresh) return hit.data;

  const flying = inflight.get(type);
  if (flying) {
    return hit ? hit.data : flying;
  }

  const request = getUploadConfigApi(type)
    .then((res) => {
      cache.set(type, { data: res, fetchedAt: Date.now() });
      return res;
    })
    .finally(() => {
      inflight.delete(type);
    });

  inflight.set(type, request);

  if (hit) {
    // 后台 revalidate，吞掉错误以保留可用旧缓存
    request.catch(() => {
      // 静默
    });
    return hit.data;
  }

  return request;
}

/**
 * 失效上传规则缓存。
 * - 传 type：失效单个类型
 * - 不传：失效全部
 *
 * 调用时机：后台保存影响上传规则的设置项之后。
 * 同源其他标签页通过 BroadcastChannel 同步失效。
 */
export function invalidateUploadConfig(type?: UploadType): void {
  if (type) {
    cache.delete(type);
  } else {
    cache.clear();
    optionsCache = null;
  }
  broadcastChannel?.postMessage({
    kind: 'invalidate',
    type,
  } satisfies InvalidateMessage);
}

/**
 * 获取上传公共选项（带缓存 + 在途去重）。
 */
export async function getUploadOptionsCached(): Promise<UploadApi.UploadOptions> {
  const now = Date.now();
  if (optionsCache && now - optionsCache.fetchedAt < TTL_MS) {
    return optionsCache.data;
  }

  if (optionsInflight) {
    return optionsCache ? optionsCache.data : optionsInflight;
  }

  optionsInflight = getUploadOptionsApi()
    .then((res) => {
      optionsCache = { data: res, fetchedAt: Date.now() };
      return res;
    })
    .finally(() => {
      optionsInflight = null;
    });

  if (optionsCache) {
    optionsInflight.catch(() => {
      // 静默保留旧缓存
    });
    return optionsCache.data;
  }

  return optionsInflight;
}
