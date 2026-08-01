# APP 端源码占位

本目录用于存放 ListenWrite APP 客户端源码（Android / iOS）。

当前源码暂未纳入仓库，后续补充后替换本文件。

## 与后端的对接

- API 入口：`POST https://<你的域名>/web/app_api.php`
- 鉴权方式：`Authorization: Bearer <token>`（token 由 `login` / `register` 接口返回）
- 完整接口清单与限流策略见 [`../web/README.md`](../web/README.md) 的「APP API 说明」章节
- 版本检查：`action=check_version`，版本由管理后台 `web/admin.php` 发布
