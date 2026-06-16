<?php

use app\model\auth\Permission;
use think\facade\Route;

// ============================================================
// 短信服务商管理
// ============================================================
Route::group('sms/provider', function () {
    Route::get('list', 'list')->name('SmsProviderList')->option([
        '_alias' => '列表', '_desc' => '服务商列表', '_auth' => true,
    ]);
    Route::get('info/:id', 'info')->name('SmsProviderInfo')->option([
        '_alias' => '详情', '_desc' => '服务商详情', '_auth' => true,
    ]);
    Route::post('create', 'create')->name('SmsProviderCreate')->option([
        '_alias' => '创建', '_desc' => '新增服务商', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::put('update/:id', 'update')->name('SmsProviderUpdate')->option([
        '_alias' => '更新', '_desc' => '更新服务商', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::delete('delete/:id', 'delete')->name('SmsProviderDelete')->option([
        '_alias' => '删除', '_desc' => '删除服务商', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('test/:id', 'test')->name('SmsProviderTest')->option([
        '_alias' => '连通性测试', '_desc' => '校验 AccessKey 是否可用', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.sms.ProviderController/')
  ->option([
      '_group_name' => '服务商管理',
      '_group_code' => 'SmsProvider',
      '_path'       => '/sms/provider',
      '_auth'       => true,
      '_icon'       => 'lucide:server',
      '_parent'     => 'SmsConfig',
      '_component'  => '/sms/provider/index',
  ]);

// ============================================================
// 短信签名管理
// ============================================================
Route::group('sms/sign', function () {
    Route::get('list', 'list')->name('SmsSignList')->option([
        '_alias' => '列表', '_desc' => '签名列表', '_auth' => true,
    ]);
    Route::get('info/:id', 'info')->name('SmsSignInfo')->option([
        '_alias' => '详情', '_desc' => '签名详情', '_auth' => true,
    ]);
    Route::post('import', 'import')->name('SmsSignImport')->option([
        '_alias' => '导入', '_desc' => '本地登记短信签名', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::delete('delete/:id', 'delete')->name('SmsSignDelete')->option([
        '_alias' => '删除', '_desc' => '删除本地签名', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.sms.SignController/')
  ->option([
      '_group_name' => '签名管理',
      '_group_code' => 'SmsSign',
      '_path'       => '/sms/sign',
      '_auth'       => true,
      '_icon'       => 'lucide:signature',
      '_parent'     => 'SmsConfig',
      '_component'  => '/sms/sign/index',
  ]);

// ============================================================
// 短信模板管理
// ============================================================
Route::group('sms/template', function () {
    Route::get('list', 'list')->name('SmsTemplateList')->option([
        '_alias' => '列表', '_desc' => '模板列表', '_auth' => true,
    ]);
    Route::get('info/:id', 'info')->name('SmsTemplateInfo')->option([
        '_alias' => '详情', '_desc' => '模板详情', '_auth' => true,
    ]);
    Route::post('create', 'create')->name('SmsTemplateCreate')->option([
        '_alias' => '创建', '_desc' => '提交模板审核', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::put('update/:id', 'update')->name('SmsTemplateUpdate')->option([
        '_alias' => '更新', '_desc' => '修改模板(自动触发重新审核)', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::delete('delete/:id', 'delete')->name('SmsTemplateDelete')->option([
        '_alias' => '删除', '_desc' => '删除模板(同时撤回远端)', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('syncStatus/:id', 'syncStatus')->name('SmsTemplateSyncStatus')->option([
        '_alias' => '同步状态', '_desc' => '拉取阿里云审核状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('syncAll', 'syncAll')->name('SmsTemplateSyncAll')->option([
        '_alias' => '批量同步', '_desc' => '同步指定服务商下所有模板状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('syncBatch', 'syncBatch')->name('SmsTemplateSyncBatch')->option([
        '_alias' => '勾选同步', '_desc' => '按勾选的 id 批量同步模板状态', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('createByScenes', 'createByScenes')->name('SmsTemplateCreateByScenes')->option([
        '_alias' => '按场景创建', '_desc' => '按内置场景批量创建模板', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.sms.TemplateController/')
  ->option([
      '_group_name' => '模板管理',
      '_group_code' => 'SmsTemplate',
      '_path'       => '/sms/template',
      '_auth'       => true,
      '_icon'       => 'lucide:file-text',
      '_parent'     => 'SmsConfig',
      '_component'  => '/sms/template/index',
  ]);

// ============================================================
// 短信场景绑定
// ============================================================
Route::group('sms/scene', function () {
    Route::get('list', 'list')->name('SmsSceneList')->option([
        '_alias' => '列表', '_desc' => '场景绑定列表(含未绑定场景)', '_auth' => true,
    ]);
    Route::post('bind', 'bind')->name('SmsSceneBind')->option([
        '_alias' => '绑定', '_desc' => '为场景指定服务商/模板/签名', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('saveDraft', 'saveDraft')->name('SmsSceneSaveDraft')->option([
        '_alias' => '保存草稿', '_desc' => '保存场景侧模板草稿内容', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('createTemplateAndBind', 'createTemplateAndBind')->name('SmsSceneCreateTemplateAndBind')->option([
        '_alias' => '创建模板并绑定', '_desc' => '根据场景模板草稿创建模板并绑定场景', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
    Route::post('unbind', 'unbind')->name('SmsSceneUnbind')->option([
        '_alias' => '取消绑定', '_desc' => '解除场景绑定', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.sms.SceneController/')
  ->option([
      '_group_name' => '场景绑定',
      '_group_code' => 'SmsScene',
      '_path'       => '/sms/scene',
      '_auth'       => true,
      '_icon'       => 'lucide:link-2',
      '_parent'     => 'SmsConfig',
      '_component'  => '/sms/scene/index',
  ]);

// ============================================================
// 短信频控全局配置
// ============================================================
Route::group('sms/config', function () {
    Route::get('info', 'info')->name('SmsConfigInfo')->option([
        '_alias' => '详情', '_desc' => '频控配置详情', '_auth' => true,
    ]);
    Route::post('save', 'save')->name('SmsConfigSave')->option([
        '_alias' => '保存', '_desc' => '保存频控配置', '_auth' => true, '_type' => Permission::TYPE_BUTTON,
    ]);
})->prefix('admin.sms.ConfigController/')
  ->option([
      '_group_name' => '频控设置',
      '_group_code' => 'SmsRateLimit',
      '_path'       => '/sms/config',
      '_auth'       => true,
      '_icon'       => 'lucide:gauge',
      '_parent'     => 'SmsConfig',
      '_component'  => '/sms/config/index',
  ]);
