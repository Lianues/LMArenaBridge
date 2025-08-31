# 🚀 LMArena Bridge CLI Tool

一个功能丰富的命令行工具，用于便捷地管理和配置 LMArena Bridge 的模型。使用 Rich 库提供美观的界面和丰富的交互体验。

## ✅ 项目状态

**所有功能已完成并测试通过！** 包括备份恢复功能在内的所有 CLI 功能都能正常工作。

## ✨ 功能特性

### 🎯 核心功能

- 📋 **模型列表** - 查看所有可用模型，支持多种筛选和格式
- 🔍 **模型详情** - 显示特定模型的详细信息和能力
- ⚙️ **配置查看** - 以树形结构显示当前配置
- 🗺️ **映射管理** - 管理模型名称到 ID 的映射关系
- 🔍 **智能搜索** - 支持交互式和非交互式模型搜索

### 🔧 高级功能

- 🔗 **端点映射管理** - 管理模型端点配置和会话信息
- ⚙️ **配置设置** - 动态修改和查看配置项
- ✅ **配置验证** - 检查配置文件的完整性和有效性
- 📊 **统计信息** - 显示详细的模型和配置统计
- 💾 **备份恢复** - 创建和恢复完整的配置备份
- 🚀 **服务管理** - 启动和管理 API 服务器
- 🔄 **数据更新** - 从远程或本地更新模型数据
- 📋 **版本信息** - 显示详细的版本和依赖信息

### 🎨 界面特性

- 🌈 **Rich 美化** - 彩色输出、表格、面板、树形结构
- 📊 **多种格式** - 支持表格、JSON、简单文本等输出格式
- 🔄 **交互式操作** - 支持交互式搜索和确认提示
- 📈 **进度显示** - 长时间操作显示进度条和状态

## 🛠️ 安装依赖

```bash
# 使用 uv 安装依赖
uv add rich click

# 或使用 pip
pip install rich click
```

## 📖 使用方法

### 基本命令

```bash
# 显示帮助信息
python cli.py --help

# 或使用 uv（推荐）
uv run python cli.py --help

# 或使用 main.py
python main.py --help
```

### 🎯 核心功能测试

```bash
# 1. 查看版本信息
uv run python cli.py version

# 2. 验证配置
uv run python cli.py validate

# 3. 查看模型列表
uv run python cli.py list

# 4. 创建备份
uv run python cli.py backup create --output my-backup.json

# 5. 恢复备份
uv run python cli.py backup restore my-backup.json -y
```

### 📋 列出模型

```bash
# 列出所有可用模型（表格格式）
uv run python cli.py list

# 按提供商筛选
uv run python cli.py list --provider openai

# 按组织筛选
uv run python cli.py list --organization google

# 按能力筛选
uv run python cli.py list --capability image

# 简单格式输出
uv run python cli.py list --format simple

# JSON格式输出
uv run python cli.py list --format json
```

### 🔍 查看模型详情

```bash
# 通过模型名称查看详情
uv run python cli.py show gpt-5-high

# 通过模型ID查看详情
uv run python cli.py show 983bc566-b783-4d28-b24c-3c8b08eb1086
```

### ⚙️ 查看配置

```bash
# 显示当前配置信息
uv run python cli.py config
```

### 🗺️ 管理模型映射

```bash
# 显示当前模型映射
uv run python cli.py mappings

# 添加新的模型映射
uv run python cli.py add my-model 12345-model-id

# 强制覆盖已存在的映射
uv run python cli.py add my-model 12345-model-id --force

# 删除模型映射（会提示确认）
uv run python cli.py remove my-model

# 跳过确认直接删除
uv run python cli.py remove my-model -y
```

### 🔍 搜索模型

```bash
# 交互式搜索
uv run python cli.py search --interactive

# 在交互模式中，输入关键词搜索，输入 'quit' 退出
```

### 🔗 端点映射管理

```bash
# 列出所有端点映射
uv run python cli.py endpoint list

# 添加端点映射
uv run python cli.py endpoint add my-model session-id message-id --mode direct_chat

# 添加战斗模式端点映射
uv run python cli.py endpoint add my-model session-id message-id --mode battle --target A

# 删除端点映射
uv run python cli.py endpoint remove my-model -y
```

### ⚙️ 配置设置管理

```bash
# 列出所有配置项
uv run python cli.py settings keys

# 获取特定配置项
uv run python cli.py settings get bypass_enabled

# 设置配置项
uv run python cli.py settings set bypass_enabled true
uv run python cli.py settings set stream_response_timeout_seconds 600
```

### ✅ 验证和统计

```bash
# 验证配置文件
uv run python cli.py validate

# 显示统计信息
uv run python cli.py stats

# 显示版本信息
uv run python cli.py version
```

### 💾 备份和恢复

```bash
# 创建备份
uv run python cli.py backup create --output my-backup.json

# 恢复备份
uv run python cli.py backup restore my-backup.json -y
```

### 🚀 服务器管理

```bash
# 启动API服务器
uv run python cli.py server start --host 0.0.0.0 --port 8000 --reload

# 检查服务器状态
uv run python cli.py server status --url http://127.0.0.1:8000
```

### 🔄 数据更新

```bash
# 从URL更新模型数据
uv run python cli.py update --url https://api.example.com/models

# 从本地文件更新
uv run python cli.py update --file new-models.json
```

## 🎨 界面预览

### 模型列表

```
                                   🤖 可用模型列表
╭────────────────────────────┬─────────────────────┬────────┬────────┬───────────────╮
│ 模型名称                   │ ID                  │ 组织   │ 提供商 │ 能力          │
├────────────────────────────┼─────────────────────┼────────┼────────┼───────────────┤
│ gpt-5-high                 │ 983bc566-b783-4d28… │ openai │ openai │ 📝文本 🖼️图像  │
│ gemini-2.5-pro             │ e2d9d353-6dbe-4414… │ google │ google │ 📝文本 🖼️图像  │
╰────────────────────────────┴─────────────────────┴────────┴────────┴───────────────╯
```

### 配置信息

```
🔧 LMArena Bridge 配置
├── 📋 基本信息
│   ├── 版本: 2.7.4
│   ├── 会话ID: e7889a74-83fc-41b3-a...
│   └── 消息ID: b11f4980-2205-473a-9...
├── 🎛️ 功能开关
│   ├── 绕过敏感词: ✅
│   ├── 酒馆模式: ❌
│   └── 文件床: ❌
└── 🤖 模型映射
    ├── gemini-2.5-pro → e2d9d353-6dbe-4414-b...
    └── gpt-5 → 983bc566-b783-4d28-b...
```

## 📁 文件结构

```
├── cli.py                 # CLI入口脚本
├── modules/
│   └── cli.py            # CLI核心功能模块
├── models.json           # 模型映射文件
├── available_models.json # 可用模型数据
├── model_endpoint_map.json # 端点映射配置
└── config.jsonc          # 主配置文件
```

## 🔧 技术栈

- **Click** - 命令行界面框架
- **Rich** - 终端美化和富文本显示
- **Python 3.12+** - 运行环境

## 💡 使用技巧

1. **快速查找模型**: 使用 `list` 命令的筛选功能快速找到需要的模型
2. **批量操作**: 结合 shell 脚本可以实现批量模型管理
3. **配置检查**: 定期使用 `config` 命令检查配置状态
4. **搜索功能**: 使用交互式搜索快速定位模型

## 🐛 故障排除

如果遇到问题，请检查：

1. 确保所有依赖已正确安装
2. 检查配置文件是否存在且格式正确
3. 确认 Python 版本为 3.12+
4. 使用 `uv run` 确保在正确的虚拟环境中运行

## 📝 更新日志

- **v1.0.0** - 初始版本，包含基本的模型管理功能
  - 模型列表和详情查看
  - 配置信息显示
  - 模型映射管理
  - 交互式搜索功能
  - Rich 美化界面
