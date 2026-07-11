SET NAMES utf8mb4;

UPDATE `mb_client_decoration_scheme`
SET `schema` = JSON_SET(
      `schema`,
      '$.modules[3].props.items',
      JSON_ARRAY(
        JSON_OBJECT('title', '地址管理', 'label', '地址管理', 'image', 'static/decorate/profile-service-address.svg', 'path', '/pages-sub/address/list'),
        JSON_OBJECT('title', '系统设置', 'label', '系统设置', 'image', 'static/decorate/profile-service-settings.svg', 'path', '/pages-sub/user/settings'),
        JSON_OBJECT('title', '联系客服', 'label', '联系客服', 'image', 'static/decorate/profile-service-support.svg', 'path', '')
      )
    ),
    `update_time` = CURRENT_TIMESTAMP
WHERE `type` = 'profile'
  AND `is_system` = 1
  AND `delete_time` IS NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(`schema`, '$.modules[3].id')) = 'profile-service';
