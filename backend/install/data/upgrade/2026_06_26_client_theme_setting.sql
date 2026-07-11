SET @client_config_group_id := (
  SELECT `id`
  FROM `mb_setting_group`
  WHERE `code` = 'ClientConfig'
  LIMIT 1
);

SET @client_theme_user_select_enabled := '1';
SET @client_theme_admin_mode := 'system';
SET @client_theme_admin_theme_id := '';
SET @client_theme_legacy_found := 0;

SET @client_theme_has_setting_table := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mb_client_theme_setting'
);

SET @client_theme_setting_sql := IF(
  @client_theme_has_setting_table > 0,
  'SELECT @client_theme_legacy_found := 1, @client_theme_user_select_enabled := CAST(`user_select_enabled` AS CHAR), @client_theme_admin_mode := `admin_theme_mode`, @client_theme_admin_theme_id := IFNULL(CAST(`admin_theme_id` AS CHAR), '''') FROM `mb_client_theme_setting` WHERE `id` = 1 LIMIT 1',
  'SELECT 1'
);
PREPARE client_theme_setting_stmt FROM @client_theme_setting_sql;
EXECUTE client_theme_setting_stmt;
DEALLOCATE PREPARE client_theme_setting_stmt;

SET @client_theme_has_policy_table := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mb_client_theme_policy'
);

SET @client_theme_policy_sql := IF(
  @client_theme_legacy_found = 0 AND @client_theme_has_policy_table > 0,
  'SELECT @client_theme_legacy_found := 1, @client_theme_user_select_enabled := CAST(`allow_user_select` AS CHAR), @client_theme_admin_mode := `default_mode`, @client_theme_admin_theme_id := IFNULL(CAST(`default_theme_id` AS CHAR), '''') FROM `mb_client_theme_policy` WHERE `id` = 1 LIMIT 1',
  'SELECT 1'
);
PREPARE client_theme_policy_stmt FROM @client_theme_policy_sql;
EXECUTE client_theme_policy_stmt;
DEALLOCATE PREPARE client_theme_policy_stmt;

INSERT INTO `mb_setting`
(`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`)
SELECT
  @client_config_group_id,
  '允许用户自选主题',
  'client_theme_user_select_enabled',
  @client_theme_user_select_enabled,
  'switch',
  NULL,
  NULL,
  NULL,
  '开启后用户选择优先；关闭后管理员指定主题强制生效',
  130
WHERE @client_config_group_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `mb_setting`
    WHERE `group_id` = @client_config_group_id
      AND `code` = 'client_theme_user_select_enabled'
  );

INSERT INTO `mb_setting`
(`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`)
SELECT
  @client_config_group_id,
  '管理员指定主题模式',
  'client_theme_admin_mode',
  @client_theme_admin_mode,
  'select',
  '[{"label":"跟随系统","value":"system"},{"label":"浅色","value":"light"},{"label":"深色","value":"dark"},{"label":"自定义","value":"custom"}]',
  NULL,
  NULL,
  '管理员统一指定的客户端主题模式',
  140
WHERE @client_config_group_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `mb_setting`
    WHERE `group_id` = @client_config_group_id
      AND `code` = 'client_theme_admin_mode'
  );

INSERT INTO `mb_setting`
(`group_id`, `name`, `code`, `value`, `type`, `options`, `rules`, `placeholder`, `remark`, `sort`)
SELECT
  @client_config_group_id,
  '管理员指定自定义主题ID',
  'client_theme_admin_theme_id',
  @client_theme_admin_theme_id,
  'input',
  NULL,
  NULL,
  NULL,
  '仅管理员指定主题模式为自定义时有效',
  150
WHERE @client_config_group_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `mb_setting`
    WHERE `group_id` = @client_config_group_id
      AND `code` = 'client_theme_admin_theme_id'
  );
