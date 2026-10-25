# Folia 可选音乐组件

Folia 是 ListenWrite 的**可选**音乐组件，独立运行在 Docker 中。主程序不依赖它；未安装时，主界面的「Folia 音乐」卡片会显示安装提示。

- 上游：<https://github.com/chthollyphile/folia-major>
- 许可证：AGPL-3.0
- 固定版本：见 `release.json`
- 默认端口：`18080`（Web）、`13000`（同步服务，默认仅本机）

## 安装

在 ListenWrite 根目录、已安装 Docker Engine + Compose v2 的机器上执行：

**Linux / Ubuntu：**

```bash
bash scripts/folia/install.sh
```

**Windows（Docker Desktop）：**

```powershell
powershell -ExecutionPolicy Bypass -File scripts\folia\install.ps1
```

> PowerShell 脚本使用 **UTF-8 with BOM + CRLF**，避免 Windows PowerShell 5.1 把中文读成乱码。
> `.env` 和 `folia.json` 由脚本以 UTF-8 无 BOM 写入，避免 Docker Compose 解析异常。

首次安装会联网拉取镜像（数百 MB）。脚本会：

1. 创建 `folia-runtime/`
2. 写入 `.env`（含随机 Sync Token）
3. 拉取固定版本镜像
4. 启动网关、Web API、网易云/酷狗/QQ API、同步服务
5. 等待健康检查
6. 写入 `web/data/folia.json`

安装完成后，刷新 ListenWrite 主界面，点击「Folia 音乐」。

> 安装脚本**不会**由网页自动执行，也不会随 ListenWrite 离线安装包自动运行。

## 常用命令

```bash
# 查看状态
curl -fsS http://127.0.0.1:18080/healthz

# 查看日志
cd folia-runtime && docker compose logs -f

# 停止 / 启动
cd folia-runtime && docker compose down
cd folia-runtime && docker compose up -d --wait

# 检查审核更新
bash scripts/folia/update.sh --check

# 执行审核更新（会重启容器，播放会中断）
bash scripts/folia/update.sh

# Windows
powershell -ExecutionPolicy Bypass -File scripts\folia\update.ps1 -Check
powershell -ExecutionPolicy Bypass -File scripts\folia\update.ps1
```

## 更新策略

网页端只认 `release.json`，不直接跟随上游 `latest`：

1. 上游发版
2. 我们在测试机验收
3. 确认兼容后更新 `release.json` 的 `stack_version`
4. 网页/更新脚本才提示新版本

更新失败会自动回滚到上一个版本；`.env` 会备份在 `folia-runtime/.env.before-*`。

## 已知限制

- 首次安装需要联网和 Docker；离线包不包含 Folia 镜像
- 本地音乐导入、PWA 需要 HTTPS；HTTP 局域网下只能在线播放
- AI 主题需要填写 Gemini/OpenAI Key
- 同步服务默认只监听 `127.0.0.1`；多设备同步需修改 `.env` 的 `FOLIA_SYNC_BIND`
- Folia 更新后仍需重新验收；不要直接把版本改成 `latest`
