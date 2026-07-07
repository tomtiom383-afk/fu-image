# 芙的床

一个轻量级的自托管图床应用，前端使用 Vue 3 + Tailwind CSS，后端使用原生 PHP。

## 功能

- 拖拽 / 点击 / 粘贴上传图片
- 支持 JPG、PNG、GIF、WebP、SVG 等常见图片格式
- 图片历史记录与分页展示
- 管理员登录、API Key 上传
- 可选 WebP 转换、自动复制链接
- 响应式布局，支持亮色 / 暗色主题
- 缩略图与水印（可选）

## 目录结构

```
.
├── api/v1/          # 后端 API 入口
├── assets/          # 前端静态资源（JS、CSS、字体）
├── config/          # 配置文件
│   ├── config.example.json   # 配置模板
│   └── config.json           # 运行时配置（不提交到仓库）
├── src/             # PHP 核心类
├── index.html       # 前端入口
└── README.md        # 本文件
```

## 环境要求

- PHP >= 8.1
- Web 服务器（Nginx / Apache / Caddy）
- 已启用 `file_uploads` 和相关图片处理扩展（`gd` 或 `imagick`，项目默认使用 GD）

## 安装

1. 克隆仓库到 Web 服务器目录：

   ```bash
   git clone https://github.com/yourname/image-hosting.git
   cd image-hosting
   ```

2. 复制配置模板：

   ```bash
   cp config/config.example.json config/config.json
   ```

3. 编辑 `config/config.json`，修改以下关键配置：

   | 配置项 | 说明 |
   |--------|------|
   | `site.base_url` | 你的站点地址，例如 `https://image.example.com` |
   | `auth.admin_user` | 管理员用户名 |
   | `auth.password_hash` | 管理员密码的 bcrypt 哈希 |
   | `auth.api_keys` | 上传用的 API Key，键名为 Key 值 |
   | `cors.allowed_origins` | 与 `site.base_url` 保持一致 |

4. 创建数据目录并确保 Web 服务器进程可写：

   ```bash
   mkdir -p /data/images /data/meta
   chown -R www-data:www-data /data/images /data/meta
   ```

5. 配置 Web 服务器将请求指向项目根目录，并确保 PHP 能正常解析。

## 生成密码哈希

在命令行运行 PHP：

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
```

将输出填入 `config/config.json` 的 `auth.password_hash`。

## 生成 API Key

```bash
openssl rand -hex 16
```

将输出替换 `config/config.json` 中 `auth.api_keys` 的键名，例如：

```json
"auth": {
    "api_keys": {
        "a1b2c3d4e5f6789012345678": { "name": "default", "expires": 0 }
    }
}
```

## 安全提示

- `config/config.json` 已加入 `.gitignore`，请勿将其提交到版本控制。
- 生产环境建议使用 HTTPS。
- 定期更换 API Key，并为不同客户端分配不同 Key。

## 配置示例

参考 `config/config.example.json` 中的占位符替换为实际值即可。

## API 文档

登录管理员账号后，在前端“API”页面可查看当前 API 端点和示例 curl 命令。

## 许可证

MIT
