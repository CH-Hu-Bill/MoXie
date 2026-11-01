# 重新构建 bundle.js

`index.html` 加载的是 `dist/bundle.js`，不直接运行 `src/`。
这样不依赖浏览器 ES Modules 支持，也能把 age/events/talents 数据一起打包，避免服务器 MIME 或 `.txt` 访问限制导致白屏。

修改 `src/` 或 `data/*.txt` 后，在本目录执行：

```bash
npx --yes esbuild src/index.js \
  --bundle \
  --format=iife \
  --target=es2017 \
  --minify \
  --loader:.txt=json \
  --outfile=dist/bundle.js
```

然后把 `dist/bundle.js` 一起更新。`index.html` 里的 `bundle.js?v=2` 可用于强制浏览器刷新缓存。
