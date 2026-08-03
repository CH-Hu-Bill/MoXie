# HarmonyOS 应用（暂停开发）

`code/harmony/` 目录包含 MoXie 的鸿蒙原生应用源码（ArkTS + ArkUI）。

## 当前状态

分支 `feat/harmonyos` 已完成：

- 完整的 ArkTS 项目结构（32 个文件，~2100 行代码）
- 5 Tab 页面：学习/生活/搜索/画廊/我的
- 登录注册、班级选择绑定、单词库/错题本/任务列表
- 日历 + Vlog 列表 + RichEditor 编辑器
- 画廊全屏查看、全局搜索、个人资料
- 手绘风格主题色预留
- TTS 音频播放（Youdao API）
- 下拉刷新组件

## 暂停原因

鸿蒙签名需要 Release 证书（需企业认证开发者账号），当前账号仅支持调试证书，且需绑定设备 UDID。考虑到维护成本，暂时搁置，后续有条件再继续。

## 后续恢复开发需要做的

1. 华为开发者账号升级为企业认证
2. 在 AGC 申请 Release 证书 + Provisioning Profile
3. 将 `.p12`、`.cer`、`.p7b` 三个文件分别 base64 编码后设为 GitHub Secrets
4. 参考 `harmony-next-pipeline`（https://github.com/ohosvscode/harmony-next-pipeline）的 Docker 镜像配置 CI
5. 调整 `entry/src/main/ets/common/Constants.ets` 中的 `BASE_URL` 为实际服务器地址
6. 添加应用图标到 `entry/src/main/resources/base/media/app_icon.png`