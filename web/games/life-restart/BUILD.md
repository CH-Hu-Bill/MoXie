# 重新构建 bundle.js

`index.html` 只加载 `dist/bundle.js`，不直接运行 `src/`，也不再单独加载 jQuery。
这样不依赖浏览器 ES Modules 支持，也不会因为服务器拦截 `vendor/`、`.txt` 或 MIME 配置问题而白屏。

修改 `src/`、`data/*.txt` 或 jQuery 后，在本目录执行：

```bash
npx --yes esbuild src/index.js \
  --bundle \
  --format=iife \
  --target=es2017 \
  --minify \
  --loader:.txt=json \
  --outfile=dist/app.bundle.js

( cat vendor/jquery.min.js; printf '\n;\n'; cat dist/app.bundle.js ) > dist/bundle.js
rm -f dist/app.bundle.js
```

然后把 `dist/bundle.js` 一起更新。`index.html` 里的 `bundle.js?v=3` 可用于强制浏览器刷新缓存。
