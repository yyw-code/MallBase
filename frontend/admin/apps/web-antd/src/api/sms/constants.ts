/**
 * 短信模块前端常量
 *
 * 与后端 SmsProvider::DRIVER_* / SmsSign::AUDIT_* / SmsTemplate::AUDIT_* 保持一致。
 */

export const SMS_DRIVER = {
  ALIYUN: 'aliyun',
  MOCK: 'mock',
} as const;

export type SmsDriverCode = (typeof SMS_DRIVER)[keyof typeof SMS_DRIVER];

export const SMS_AUDIT_STATUS = {
  SUBMITTING: 'submitting',
  PENDING: 'pending',
  PASSED: 'passed',
  REJECTED: 'rejected',
  LOCAL_ONLY: 'local_only',
} as const;

export type SmsAuditStatus =
  (typeof SMS_AUDIT_STATUS)[keyof typeof SMS_AUDIT_STATUS];

/**
 * 从模板内容中提取占位符名称
 *
 * 镜像后端 SmsTemplate::extractPlaceholders,正则匹配 ${xxx} 形式的变量名并去重。
 * 场景绑定判断优先使用后端派生的 placeholders 字段,本工具用于表单输入态的实时识别提示与兜底。
 */
export function extractPlaceholders(content?: null | string): string[] {
  if (!content) {
    return [];
  }
  const result: string[] = [];
  const regex = /\$\{(\w+)\}/g;
  let match: null | RegExpExecArray = regex.exec(content);
  while (match !== null) {
    const name = match[1];
    if (name && !result.includes(name)) {
      result.push(name);
    }
    match = regex.exec(content);
  }
  return result;
}
