# Folia 验收清单（GitHub Actions）

工作流：`.github/workflows/folia-smoke.yml`  
触发：推送到 `feature/folia-integration`，或在 Actions 页面手动运行。

## 云端验收内容

1. `bash -n` 检查安装/更新脚本语法
2. `php -l` 检查新增 PHP 文件（runner 有 PHP 时）
3. 检查 PowerShell 脚本 UTF-8 BOM，并用 `pwsh` 解析语法
4. 运行 `scripts/folia/install.sh`
   - 拉取固定版本 `1.2.1` 镜像
   - 启动网关、Web API、网易云/酷狗/QQ API、同步服务
   - 等待 `/healthz` 通过
5. 检查：
   - `/healthz`
   - `/api/healthz`
   - `/netease/`
   - `/kugou/`
   - `/qq/login/status`
   - 首页包含 `Folia`
6. 上传 `web/data/folia.json` 和 `scripts/folia/release.json` 作为 artifact
7. 失败时输出容器日志；结束时删除容器

## 本机/服务器验收

```bash
bash scripts/folia/install.sh
curl -fsS http://127.0.0.1:18080/healthz
curl -fsS http://127.0.0.1:18080/api/healthz
```

想先看转场（不安装 Folia）：

```text
http://<服务器IP>:8000/music.php?id=<班级ID>&preview=1
```

然后在 ListenWrite 主界面点击「Folia 音乐」：

- 未安装：显示安装命令
- 已安装未启动：显示等待并自动重试
- 已运行：手绘转场后进入 Folia
- 有新审核版本：转场页显示更新提示，先听歌或查看更新命令

## 验收后还要人工确认

- 手机/平板通过局域网 IP 打开 Folia
- 网易云扫码登录、搜索、播放、歌词
- 本地音乐导入（需要 HTTPS）
- 关机重启后 Docker 容器自动恢复
- 更新脚本的备份/回滚路径
