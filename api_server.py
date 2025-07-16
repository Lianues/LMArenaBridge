# api_server.py
# 新一代 LMArena Bridge 后端服务

import asyncio
import json
import logging
import os
import sys
import subprocess
import time
import uuid
import re
import threading
from datetime import datetime
from contextlib import asynccontextmanager

import uvicorn
import requests
from packaging.version import parse as parse_version
from fastapi import FastAPI, WebSocket, WebSocketDisconnect, Request, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import StreamingResponse, JSONResponse, Response

# --- 基础配置 ---
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# --- 全局状态与配置 ---
CONFIG = {} # 存储从 config.jsonc 加载的配置
# browser_ws 用于存储与单个油猴脚本的 WebSocket 连接。
# 注意：此架构假定只有一个浏览器标签页在工作。
# 如果需要支持多个并发标签页，需要将此扩展为字典管理多个连接。
browser_ws: WebSocket | None = None
# response_channels 用于存储每个 API 请求的响应队列。
# 键是 request_id，值是 asyncio.Queue。
response_channels: dict[str, asyncio.Queue] = {}
last_activity_time = None # 记录最后一次活动的时间
idle_monitor_thread = None # 空闲监控线程
main_event_loop = None # 主事件循环

# --- 模型映射 ---
MODEL_NAME_TO_ID_MAP = {}
DEFAULT_MODEL_ID = "f44e280a-7914-43ca-a25d-ecfcc5d48d09" # 默认模型: Claude 3.5 Sonnet

def load_config():
    """从 config.jsonc 加载配置，并处理 JSONC 注释。"""
    global CONFIG
    try:
        with open('config.jsonc', 'r', encoding='utf-8') as f:
            content = f.read()
            # 移除 // 行注释和 /* */ 块注释
            json_content = re.sub(r'//.*', '', content)
            json_content = re.sub(r'/\*.*?\*/', '', json_content, flags=re.DOTALL)
            CONFIG = json.loads(json_content)
        logger.info("成功从 'config.jsonc' 加载配置。")
        # 打印关键配置状态
        logger.info(f"  - 酒馆模式 (Tavern Mode): {'✅ 启用' if CONFIG.get('tavern_mode_enabled') else '❌ 禁用'}")
        logger.info(f"  - 绕过模式 (Bypass Mode): {'✅ 启用' if CONFIG.get('bypass_enabled') else '❌ 禁用'}")
    except (FileNotFoundError, json.JSONDecodeError) as e:
        logger.error(f"加载或解析 'config.jsonc' 失败: {e}。将使用默认配置。")
        CONFIG = {}

def load_model_map():
    """从 models.json 加载模型映射。"""
    global MODEL_NAME_TO_ID_MAP
    try:
        with open('models.json', 'r', encoding='utf-8') as f:
            MODEL_NAME_TO_ID_MAP = json.load(f)
        logger.info(f"成功从 'models.json' 加载了 {len(MODEL_NAME_TO_ID_MAP)} 个模型。")
    except (FileNotFoundError, json.JSONDecodeError) as e:
        logger.error(f"加载 'models.json' 失败: {e}。将使用空模型列表。")
        MODEL_NAME_TO_ID_MAP = {}

# --- 更新检查 ---
GITHUB_REPO = "Lianues/LMArenaBridge"

def download_and_extract_update(version):
    """下载并解压最新版本到临时文件夹。"""
    update_dir = "update_temp"
    if not os.path.exists(update_dir):
        os.makedirs(update_dir)

    try:
        zip_url = f"https://github.com/{GITHUB_REPO}/archive/refs/heads/main.zip"
        logger.info(f"正在从 {zip_url} 下载新版本...")
        response = requests.get(zip_url, timeout=60)
        response.raise_for_status()

        # 需要导入 zipfile 和 io
        import zipfile
        import io
        with zipfile.ZipFile(io.BytesIO(response.content)) as z:
            z.extractall(update_dir)
        
        logger.info(f"新版本已成功下载并解压到 '{update_dir}' 文件夹。")
        return True
    except requests.RequestException as e:
        logger.error(f"下载更新失败: {e}")
    except zipfile.BadZipFile:
        logger.error("下载的文件不是一个有效的zip压缩包。")
    except Exception as e:
        logger.error(f"解压更新时发生未知错误: {e}")
    
    return False

def check_for_updates():
    """从 GitHub 检查新版本。"""
    if not CONFIG.get("enable_auto_update", True):
        logger.info("自动更新已禁用，跳过检查。")
        return

    current_version = CONFIG.get("version", "0.0.0")
    logger.info(f"当前版本: {current_version}。正在从 GitHub 检查更新...")

    try:
        config_url = f"https://raw.githubusercontent.com/{GITHUB_REPO}/main/config.jsonc"
        response = requests.get(config_url, timeout=10)
        response.raise_for_status()

        jsonc_content = response.text
        json_content = re.sub(r'//.*', '', jsonc_content)
        json_content = re.sub(r'/\*.*?\*/', '', json_content, flags=re.DOTALL)
        remote_config = json.loads(json_content)
        
        remote_version_str = remote_config.get("version")
        if not remote_version_str:
            logger.warning("远程配置文件中未找到版本号，跳过更新检查。")
            return

        if parse_version(remote_version_str) > parse_version(current_version):
            logger.info("="*60)
            logger.info(f"🎉 发现新版本! 🎉")
            logger.info(f"  - 当前版本: {current_version}")
            logger.info(f"  - 最新版本: {remote_version_str}")
            if download_and_extract_update(remote_version_str):
                logger.info("准备应用更新。服务器将在5秒后关闭并启动更新脚本。")
                time.sleep(5)
                update_script_path = os.path.join("modules", "update_script.py")
                # 使用 Popen 启动独立进程
                subprocess.Popen([sys.executable, update_script_path])
                # 优雅地退出当前服务器进程
                os._exit(0)
            else:
                logger.error(f"自动更新失败。请访问 https://github.com/{GITHUB_REPO}/releases/latest 手动下载。")
            logger.info("="*60)
        else:
            logger.info("您的程序已是最新版本。")

    except requests.RequestException as e:
        logger.error(f"检查更新失败: {e}")
    except json.JSONDecodeError:
        logger.error("解析远程配置文件失败。")
    except Exception as e:
        logger.error(f"检查更新时发生未知错误: {e}")

# --- 模型更新 ---
def extract_models_from_html(html_content):
    """
    从 HTML 内容中提取模型数据，采用更健壮的解析方法。
    """
    script_contents = re.findall(r'<script>(.*?)</script>', html_content, re.DOTALL)
    
    for script_content in script_contents:
        if 'self.__next_f.push' in script_content and 'initialState' in script_content and 'publicName' in script_content:
            match = re.search(r'self\.__next_f\.push\(\[1,"(.*?)"\]\)', script_content, re.DOTALL)
            if not match:
                continue
            
            full_payload = match.group(1)
            
            payload_string = full_payload.split('\\n')[0]
            
            json_start_index = payload_string.find(':')
            if json_start_index == -1:
                continue
            
            json_string_with_escapes = payload_string[json_start_index + 1:]
            json_string = json_string_with_escapes.replace('\\"', '"')
            
            try:
                data = json.loads(json_string)
                
                def find_initial_state(obj):
                    if isinstance(obj, dict):
                        for key, value in obj.items():
                            if key == 'initialState' and isinstance(value, list):
                                if value and isinstance(value[0], dict) and 'publicName' in value[0]:
                                    return value
                            result = find_initial_state(value)
                            if result is not None:
                                return result
                    elif isinstance(obj, list):
                        for item in obj:
                            result = find_initial_state(item)
                            if result is not None:
                                return result
                    return None

                models = find_initial_state(data)
                if models:
                    logger.info(f"成功从脚本块中提取到 {len(models)} 个模型。")
                    return models
            except json.JSONDecodeError as e:
                logger.error(f"解析提取的JSON字符串时出错: {e}")
                continue

    logger.error("错误：在HTML响应中找不到包含有效模型数据的脚本块。")
    return None

def compare_and_update_models(new_models_list, models_path):
    """
    比较新旧模型列表，打印差异，并用新列表更新本地 models.json 文件。
    """
    try:
        with open(models_path, 'r', encoding='utf-8') as f:
            old_models = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        old_models = {}

    new_models_dict = {model['publicName']: model for model in new_models_list if 'publicName' in model}
    old_models_set = set(old_models.keys())
    new_models_set = set(new_models_dict.keys())

    added_models = new_models_set - old_models_set
    removed_models = old_models_set - new_models_set
    
    logger.info("--- 模型列表更新检查 ---")
    has_changes = False

    if added_models:
        has_changes = True
        logger.info("\n[+] 新增模型:")
        for name in sorted(list(added_models)):
            model = new_models_dict[name]
            logger.info(f"  - 名称: {name}, ID: {model.get('id')}, 组织: {model.get('organization', 'N/A')}")

    if removed_models:
        has_changes = True
        logger.info("\n[-] 删除模型:")
        for name in sorted(list(removed_models)):
            logger.info(f"  - 名称: {name}, ID: {old_models.get(name)}")

    logger.info("\n[*] 共同模型检查:")
    changed_models = 0
    for name in sorted(list(new_models_set.intersection(old_models_set))):
        new_id = new_models_dict[name].get('id')
        old_id = old_models.get(name)
        if new_id != old_id:
            has_changes = True
            changed_models += 1
            logger.info(f"  - ID 变更: '{name}' 旧ID: {old_id} -> 新ID: {new_id}")
    
    if changed_models == 0:
        logger.info("  - 共同模型的ID无变化。")

    if not has_changes:
        logger.info("\n结论: 模型列表无任何变化，无需更新文件。")
        logger.info("--- 检查完毕 ---")
        return

    logger.info("\n结论: 检测到模型变更，正在更新 'models.json'...")
    updated_model_map = {model['publicName']: model.get('id') for model in new_models_list if 'publicName' in model and 'id' in model}
    try:
        with open(models_path, 'w', encoding='utf-8') as f:
            json.dump(updated_model_map, f, indent=4, ensure_ascii=False)
        logger.info(f"'{models_path}' 已成功更新，包含 {len(updated_model_map)} 个模型。")
        load_model_map()
    except IOError as e:
        logger.error(f"写入 '{models_path}' 文件时出错: {e}")
    
    logger.info("--- 检查与更新完毕 ---")

# --- 自动重启逻辑 ---
def restart_server():
    """优雅地通知客户端刷新，然后重启服务器。"""
    logger.warning("="*60)
    logger.warning("检测到服务器空闲超时，准备自动重启...")
    logger.warning("="*60)
    
    # 1. (异步) 通知浏览器刷新
    async def notify_browser_refresh():
        if browser_ws:
            try:
                # 优先发送 'reconnect' 指令，让前端知道这是一个计划内的重启
                await browser_ws.send_text(json.dumps({"command": "reconnect"}, ensure_ascii=False))
                logger.info("已向浏览器发送 'reconnect' 指令。")
            except Exception as e:
                logger.error(f"发送 'reconnect' 指令失败: {e}")
    
    # 在主事件循环中运行异步通知函数
    # 使用`asyncio.run_coroutine_threadsafe`确保线程安全
    if browser_ws and browser_ws.client_state.name == 'CONNECTED' and main_event_loop:
        asyncio.run_coroutine_threadsafe(notify_browser_refresh(), main_event_loop)
    
    # 2. 延迟几秒以确保消息发送
    time.sleep(3)
    
    # 3. 执行重启
    logger.info("正在重启服务器...")
    os.execv(sys.executable, ['python'] + sys.argv)

def idle_monitor():
    """在后台线程中运行，监控服务器是否空闲。"""
    global last_activity_time
    
    # 等待，直到 last_activity_time 被首次设置
    while last_activity_time is None:
        time.sleep(1)
        
    logger.info("空闲监控线程已启动。")
    
    while True:
        if CONFIG.get("enable_idle_restart", False):
            timeout = CONFIG.get("idle_restart_timeout_seconds", 300)
            
            # 如果超时设置为-1，则禁用重启检查
            if timeout == -1:
                time.sleep(10) # 仍然需要休眠以避免繁忙循环
                continue

            idle_time = (datetime.now() - last_activity_time).total_seconds()
            
            if idle_time > timeout:
                logger.info(f"服务器空闲时间 ({idle_time:.0f}s) 已超过阈值 ({timeout}s)。")
                restart_server()
                break # 退出循环，因为进程即将被替换
                
        # 每 10 秒检查一次
        time.sleep(10)

# --- FastAPI 生命周期事件 ---
@asynccontextmanager
async def lifespan(app: FastAPI):
    """在服务器启动时运行的生命周期函数。"""
    global idle_monitor_thread, last_activity_time, main_event_loop
    main_event_loop = asyncio.get_running_loop() # 获取主事件循环
    load_config() # 首先加载配置
    
    # --- 打印当前的操作模式 ---
    mode = CONFIG.get("id_updater_last_mode", "direct_chat")
    target = CONFIG.get("id_updater_battle_target", "A")
    logger.info("="*60)
    logger.info(f"  当前操作模式: {mode.upper()}")
    if mode == 'battle':
        logger.info(f"  - Battle 模式目标: Assistant {target}")
    logger.info("  (可通过运行 id_updater.py 修改模式)")
    logger.info("="*60)

    check_for_updates() # 检查程序更新
    load_model_map() # 加载模型映射
    logger.info("服务器启动完成。等待油猴脚本连接...")

    # 在模型更新后，标记活动时间的起点
    last_activity_time = datetime.now()
    
    # 启动空闲监控线程
    if CONFIG.get("enable_idle_restart", False):
        idle_monitor_thread = threading.Thread(target=idle_monitor, daemon=True)
        idle_monitor_thread.start()

    yield
    logger.info("服务器正在关闭。")

app = FastAPI(lifespan=lifespan)

# --- CORS 中间件配置 ---
# 允许所有来源、所有方法、所有请求头，这对于本地开发工具是安全的。
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- 辅助函数 ---
def save_config():
    """将当前的 CONFIG 对象写回 config.jsonc 文件，保留注释。"""
    try:
        # 读取原始文件以保留注释等
        with open('config.jsonc', 'r', encoding='utf-8') as f:
            lines = f.readlines()

        # 使用正则表达式安全地替换值
        def replacer(key, value, content):
            # 这个正则表达式会找到 key，然后匹配它的 value 部分，直到逗号或右花括号
            pattern = re.compile(rf'("{key}"\s*:\s*").*?("?)(,?\s*)$', re.MULTILINE)
            replacement = rf'\g<1>{value}\g<2>\g<3>'
            if not pattern.search(content): # 如果 key 不存在，就添加到文件末尾（简化处理）
                 content = re.sub(r'}\s*$', f'  ,"{key}": "{value}"\n}}', content)
            else:
                 content = pattern.sub(replacement, content)
            return content

        content_str = "".join(lines)
        content_str = replacer("session_id", CONFIG["session_id"], content_str)
        content_str = replacer("message_id", CONFIG["message_id"], content_str)
        
        with open('config.jsonc', 'w', encoding='utf-8') as f:
            f.write(content_str)
        logger.info("✅ 成功将会话信息更新到 config.jsonc。")
    except Exception as e:
        logger.error(f"❌ 写入 config.jsonc 时发生错误: {e}", exc_info=True)


def _process_openai_message(message: dict) -> dict:
    """
    处理OpenAI消息，分离文本和附件。
    - 将多模态内容列表分解为纯文本和附件列表。
    - 确保 user 角色的空内容被替换为空格，以避免 LMArena 出错。
    - 为附件生成基础结构。
    """
    content = message.get("content")
    role = message.get("role")
    attachments = []
    text_content = ""

    if isinstance(content, list):
        
        text_parts = []
        for part in content:
            if part.get("type") == "text":
                text_parts.append(part.get("text", ""))
            elif part.get("type") == "image_url":
                image_url_data = part.get("image_url", {})
                url = image_url_data.get("url")
                if url and url.startswith("data:image"):
                    try:
                        content_type = url.split(';')[0].split(':')[1]
                        file_extension = content_type.split('/')[1] if '/' in content_type else 'png'
                        file_name = f"image_{uuid.uuid4()}.{file_extension}"
                        
                        attachments.append({
                            "name": file_name,
                            "contentType": content_type,
                            "url": url
                        })
                    except IndexError:
                        logger.warning(f"无法解析的 base64 data URI: {url[:50]}...")

        text_content = "\n\n".join(text_parts)
    elif isinstance(content, str):
        text_content = content

    
    if role == "user" and not text_content.strip():
        text_content = " "

    return {
        "role": role,
        "content": text_content,
        "attachments": attachments
    }

def convert_openai_to_lmarena_payload(openai_data: dict, session_id: str, message_id: str) -> dict:
    """
    将 OpenAI 请求体转换为油猴脚本所需的简化载荷，并应用酒馆模式、绕过模式以及对战模式。
    """
    # 1. 处理所有消息，分离文本和附件
    processed_messages = [_process_openai_message(msg.copy()) for msg in openai_data.get("messages", [])]

    # 2. 应用酒馆模式 (Tavern Mode)
    if CONFIG.get("tavern_mode_enabled"):
        system_prompts = [msg['content'] for msg in processed_messages if msg['role'] == 'system']
        other_messages = [msg for msg in processed_messages if msg['role'] != 'system']
        
        merged_system_prompt = "\n\n".join(system_prompts)
        final_messages = []
        
        if merged_system_prompt:
            # 系统消息不应有附件
            final_messages.append({"role": "system", "content": merged_system_prompt, "attachments": []})
        
        final_messages.extend(other_messages)
        processed_messages = final_messages

    # 3. 确定目标模型 ID
    model_name = openai_data.get("model", "claude-3-5-sonnet-20241022")
    target_model_id = MODEL_NAME_TO_ID_MAP.get(model_name, DEFAULT_MODEL_ID)
    
    # 4. 构建消息模板
    message_templates = []
    for msg in processed_messages:
        message_templates.append({
            "role": msg["role"],
            "content": msg.get("content", ""),
            "attachments": msg.get("attachments", [])
        })

    # 5. 应用绕过模式 (Bypass Mode)
    if CONFIG.get("bypass_enabled"):
        # 绕过模式总是添加一个 position 'a' 的用户消息
        message_templates.append({"role": "user", "content": " ", "participantPosition": "a", "attachments": []})

    # 6. 应用参与者位置 (Participant Position)
    mode = CONFIG.get("id_updater_last_mode", "direct_chat")
    target_participant = CONFIG.get("id_updater_battle_target", "A").lower()
    logger.info(f"正在根据模式 '{mode}' 设置 Participant Positions...")

    for msg in message_templates:
        if msg['role'] == 'system':
            if mode == 'battle':
                # Battle 模式: system 与用户选择的助手在同一边 (A则a, B则b)
                msg['participantPosition'] = target_participant
            else:
                # DirectChat 模式: system 固定为 'b'
                msg['participantPosition'] = 'b'
        elif mode == 'battle':
            # Battle 模式下，非 system 消息使用用户选择的目标 participant
            msg['participantPosition'] = target_participant
        else: # DirectChat 模式
            # DirectChat 模式下，非 system 消息使用默认的 'a'
            msg['participantPosition'] = 'a'

    return {
        "message_templates": message_templates,
        "target_model_id": target_model_id,
        "session_id": session_id,
        "message_id": message_id
    }

# --- OpenAI 格式化辅助函数 (确保JSON序列化稳健) ---
def format_openai_chunk(content: str, model: str, request_id: str) -> str:
    """格式化为 OpenAI 流式块。"""
    chunk = {
        "id": request_id, "object": "chat.completion.chunk",
        "created": int(time.time()), "model": model,
        "choices": [{"index": 0, "delta": {"content": content}, "finish_reason": None}]
    }
    return f"data: {json.dumps(chunk, ensure_ascii=False)}\n\n"

def format_openai_finish_chunk(model: str, request_id: str, reason: str = 'stop') -> str:
    """格式化为 OpenAI 结束块。"""
    chunk = {
        "id": request_id, "object": "chat.completion.chunk",
        "created": int(time.time()), "model": model,
        "choices": [{"index": 0, "delta": {}, "finish_reason": reason}]
    }
    return f"data: {json.dumps(chunk, ensure_ascii=False)}\n\ndata: [DONE]\n\n"

def format_openai_error_chunk(error_message: str, model: str, request_id: str) -> str:
    """格式化为 OpenAI 错误块。"""
    content = f"\n\n[LMArena Bridge Error]: {error_message}"
    return format_openai_chunk(content, model, request_id)

def format_openai_non_stream_response(content: str, model: str, request_id: str, reason: str = 'stop') -> dict:
    """构建符合 OpenAI 规范的非流式响应体。"""
    return {
        "id": request_id,
        "object": "chat.completion",
        "created": int(time.time()),
        "model": model,
        "choices": [{
            "index": 0,
            "message": {"role": "assistant", "content": content},
            "finish_reason": reason,
        }],
        "usage": {
            "prompt_tokens": 0,
            "completion_tokens": len(content) // 4,
            "total_tokens": len(content) // 4,
        },
    }

async def _process_lmarena_stream(request_id: str):
    """
    核心内部生成器：处理来自浏览器的原始数据流，并产生结构化事件。
    事件类型: ('content', str), ('finish', str), ('error', str)
    """
    queue = response_channels.get(request_id)
    if not queue:
        logger.error(f"PROCESSOR [ID: {request_id[:8]}]: 无法找到响应通道。")
        yield 'error', 'Internal server error: response channel not found.'
        return

    buffer = ""
    timeout = CONFIG.get("stream_response_timeout_seconds",360)
    text_pattern = re.compile(r'[ab]0:"((?:\\.|[^"\\])*)"')
    finish_pattern = re.compile(r'[ab]d:(\{.*?"finishReason".*?\})')
    error_pattern = re.compile(r'(\{\s*"error".*?\})', re.DOTALL)
    cloudflare_patterns = [r'<title>Just a moment...</title>', r'Enable JavaScript and cookies to continue']

    try:
        while True:
            try:
                raw_data = await asyncio.wait_for(queue.get(), timeout=timeout)
            except asyncio.TimeoutError:
                logger.warning(f"PROCESSOR [ID: {request_id[:8]}]: 等待浏览器数据超时（{timeout}秒）。")
                yield 'error', f'Response timed out after {timeout} seconds.'
                return

            # 1. 检查来自 WebSocket 端的直接错误或终止信号
            if isinstance(raw_data, dict) and 'error' in raw_data:
                error_msg = raw_data.get('error', 'Unknown browser error')
                # 增强：检查错误消息本身是否包含Cloudflare页面
                if isinstance(error_msg, str) and any(re.search(p, error_msg, re.IGNORECASE) for p in cloudflare_patterns):
                    friendly_error_msg = "检测到 Cloudflare 人机验证页面。请在浏览器中刷新 LMArena 页面并手动完成验证，然后重试请求。"
                    if browser_ws:
                        try:
                            await browser_ws.send_text(json.dumps({"command": "refresh"}, ensure_ascii=False))
                            logger.info(f"PROCESSOR [ID: {request_id[:8]}]: 在错误消息中检测到CF并已发送刷新指令。")
                        except Exception as e:
                            logger.error(f"PROCESSOR [ID: {request_id[:8]}]: 发送刷新指令失败: {e}")
                    yield 'error', friendly_error_msg
                else:
                    yield 'error', error_msg
                return
            if raw_data == "[DONE]":
                break

            buffer += "".join(str(item) for item in raw_data) if isinstance(raw_data, list) else raw_data

            if any(re.search(p, buffer, re.IGNORECASE) for p in cloudflare_patterns):
                error_msg = "检测到 Cloudflare 人机验证页面。请在浏览器中刷新 LMArena 页面并手动完成验证，然后重试请求。"
                if browser_ws:
                    try:
                        await browser_ws.send_text(json.dumps({"command": "refresh"}, ensure_ascii=False))
                        logger.info(f"PROCESSOR [ID: {request_id[:8]}]: 已向浏览器发送页面刷新指令。")
                    except Exception as e:
                        logger.error(f"PROCESSOR [ID: {request_id[:8]}]: 发送刷新指令失败: {e}")
                yield 'error', error_msg
                return
            
            if (error_match := error_pattern.search(buffer)):
                try:
                    error_json = json.loads(error_match.group(1))
                    yield 'error', error_json.get("error", "来自 LMArena 的未知错误")
                    return
                except json.JSONDecodeError: pass

            while (match := text_pattern.search(buffer)):
                try:
                    text_content = json.loads(f'"{match.group(1)}"')
                    if text_content: yield 'content', text_content
                except (ValueError, json.JSONDecodeError): pass
                buffer = buffer[match.end():]

            if (finish_match := finish_pattern.search(buffer)):
                try:
                    finish_data = json.loads(finish_match.group(1))
                    yield 'finish', finish_data.get("finishReason", "stop")
                except (json.JSONDecodeError, IndexError): pass
                buffer = buffer[finish_match.end():]

    except asyncio.CancelledError:
        logger.info(f"PROCESSOR [ID: {request_id[:8]}]: 任务被取消。")
    finally:
        if request_id in response_channels:
            del response_channels[request_id]
            logger.info(f"PROCESSOR [ID: {request_id[:8]}]: 响应通道已清理。")

async def stream_generator(request_id: str, model: str):
    """将内部事件流格式化为 OpenAI SSE 响应。"""
    response_id = f"chatcmpl-{uuid.uuid4()}"
    logger.info(f"STREAMER [ID: {request_id[:8]}]: 流式生成器启动。")
    
    finish_reason_to_send = 'stop'  # 默认的结束原因

    async for event_type, data in _process_lmarena_stream(request_id):
        if event_type == 'content':
            yield format_openai_chunk(data, model, response_id)
        elif event_type == 'finish':
            # 记录结束原因，但不要立即返回，等待浏览器发送 [DONE]
            finish_reason_to_send = data
            if data == 'content-filter':
                warning_msg = "\n\n响应被终止，可能是上下文超限或者模型内部审查（大概率）的原因"
                yield format_openai_chunk(warning_msg, model, response_id)
        elif event_type == 'error':
            logger.error(f"STREAMER [ID: {request_id[:8]}]: 流中发生错误: {data}")
            yield format_openai_error_chunk(str(data), model, response_id)
            yield format_openai_finish_chunk(model, response_id, reason='stop')
            return # 发生错误时，可以立即终止

    # 只有在 _process_lmarena_stream 自然结束后 (即收到 [DONE]) 才执行
    yield format_openai_finish_chunk(model, response_id, reason=finish_reason_to_send)
    logger.info(f"STREAMER [ID: {request_id[:8]}]: 流式生成器正常结束。")

async def non_stream_response(request_id: str, model: str):
    """聚合内部事件流并返回单个 OpenAI JSON 响应。"""
    response_id = f"chatcmpl-{uuid.uuid4()}"
    logger.info(f"NON-STREAM [ID: {request_id[:8]}]: 开始处理非流式响应。")
    
    full_content = []
    finish_reason = "stop"
    
    async for event_type, data in _process_lmarena_stream(request_id):
        if event_type == 'content':
            full_content.append(data)
        elif event_type == 'finish':
            finish_reason = data
            if data == 'content-filter':
                full_content.append("\n\n响应被终止，可能是上下文超限或者模型内部审查（大概率）的原因")
            # 不要在这里 break，继续等待来自浏览器的 [DONE] 信号，以避免竞态条件
        elif event_type == 'error':
            logger.error(f"NON-STREAM [ID: {request_id[:8]}]: 处理时发生错误: {data}")
            error_response = {
                "error": {
                    "message": f"[LMArena Bridge Error]: {data}",
                    "type": "bridge_error",
                    "code": "processing_error"
                }
            }
            return Response(content=json.dumps(error_response, ensure_ascii=False), status_code=500, media_type="application/json")

    final_content = "".join(full_content)
    response_data = format_openai_non_stream_response(final_content, model, response_id, reason=finish_reason)
    
    logger.info(f"NON-STREAM [ID: {request_id[:8]}]: 响应聚合完成。")
    return Response(content=json.dumps(response_data, ensure_ascii=False), media_type="application/json")

# --- WebSocket 端点 ---
@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    """处理来自油猴脚本的 WebSocket 连接。"""
    global browser_ws
    await websocket.accept()
    if browser_ws is not None:
        logger.warning("检测到新的油猴脚本连接，旧的连接将被替换。")
    logger.info("✅ 油猴脚本已成功连接 WebSocket。")
    browser_ws = websocket
    try:
        while True:
            # 等待并接收来自油猴脚本的消息
            message_str = await websocket.receive_text()
            message = json.loads(message_str)
            
            request_id = message.get("request_id")
            data = message.get("data")

            if not request_id or data is None:
                logger.warning(f"收到来自浏览器的无效消息: {message}")
                continue

            # 将收到的数据放入对应的响应通道
            if request_id in response_channels:
                await response_channels[request_id].put(data)
            else:
                logger.warning(f"⚠️ 收到未知或已关闭请求的响应: {request_id}")

    except WebSocketDisconnect:
        logger.warning("❌ 油猴脚本客户端已断开连接。")
    except Exception as e:
        logger.error(f"WebSocket 处理时发生未知错误: {e}", exc_info=True)
    finally:
        browser_ws = None
        # 清理所有等待的响应通道，以防请求被挂起
        for queue in response_channels.values():
            await queue.put({"error": "Browser disconnected during operation"})
        response_channels.clear()
        logger.info("WebSocket 连接已清理。")

# --- 模型更新端点 ---
@app.post("/update_models")
async def update_models_endpoint(request: Request):
    """
    接收来自油猴脚本的页面 HTML，提取并更新模型列表。
    """
    html_content = await request.body()
    if not html_content:
        logger.warning("模型更新请求未收到任何 HTML 内容。")
        return JSONResponse(
            status_code=400,
            content={"status": "error", "message": "No HTML content received."}
        )
    
    logger.info("收到来自油猴脚本的页面内容，开始检查并更新模型...")
    new_models_list = extract_models_from_html(html_content.decode('utf-8'))
    
    if new_models_list:
        compare_and_update_models(new_models_list, 'models.json')
        # load_model_map() is now called inside compare_and_update_models
        return JSONResponse({"status": "success", "message": "Model comparison and update complete."})
    else:
        logger.error("未能从油猴脚本提供的 HTML 中提取模型数据。")
        return JSONResponse(
            status_code=400,
            content={"status": "error", "message": "Could not extract model data from HTML."}
        )

# --- OpenAI 兼容 API 端点 ---
@app.get("/v1/models")
async def get_models():
    """提供兼容 OpenAI 的模型列表。"""
    if not MODEL_NAME_TO_ID_MAP:
        return JSONResponse(
            status_code=404,
            content={"error": "模型列表为空或 'models.json' 未找到。"}
        )
    
    return {
        "object": "list",
        "data": [
            {
                "id": model_name, 
                "object": "model",
                "created": int(asyncio.get_event_loop().time()), 
                "owned_by": "LMArenaBridge"
            }
            for model_name in MODEL_NAME_TO_ID_MAP.keys()
        ],
    }

@app.post("/v1/chat/completions")
async def chat_completions(request: Request):
    """
    处理聊天补全请求。
    接收 OpenAI 格式的请求，将其转换为 LMArena 格式，
    通过 WebSocket 发送给油猴脚本，然后流式返回结果。
    """
    global last_activity_time
    last_activity_time = datetime.now() # 更新活动时间
    logger.info(f"API请求已收到，活动时间已更新为: {last_activity_time.strftime('%Y-%m-%d %H:%M:%S')}")

    load_config()  # 实时加载最新配置，确保会话ID等信息是最新的
    # --- API Key 验证 ---
    api_key = CONFIG.get("api_key")
    if api_key:
        auth_header = request.headers.get('Authorization')
        if not auth_header or not auth_header.startswith('Bearer '):
            raise HTTPException(
                status_code=401,
                detail="未提供 API Key。请在 Authorization 头部中以 'Bearer YOUR_KEY' 格式提供。"
            )
        
        provided_key = auth_header.split(' ')[1]
        if provided_key != api_key:
            raise HTTPException(
                status_code=401,
                detail="提供的 API Key 不正确。"
            )

    if not browser_ws:
        raise HTTPException(status_code=503, detail="油猴脚本客户端未连接。请确保 LMArena 页面已打开并激活脚本。")

    try:
        openai_req = await request.json()
    except json.JSONDecodeError:
        raise HTTPException(status_code=400, detail="无效的 JSON 请求体")

    # --- 确定并验证会话信息 ---
    # 优先使用请求体中提供的ID，否则回退到配置文件中的ID
    session_id = openai_req.get("session_id") or CONFIG.get("session_id")
    message_id = openai_req.get("message_id") or CONFIG.get("message_id")

    if not session_id or not message_id or "YOUR_" in session_id or "YOUR_" in message_id:
        raise HTTPException(
            status_code=400,
            detail="会话ID或消息ID无效。请在请求体中提供，或运行 `id_updater.py` 来设置默认值。"
        )

    model_name = openai_req.get("model")
    if not model_name or model_name not in MODEL_NAME_TO_ID_MAP:
        logger.warning(f"请求的模型 '{model_name}' 不在 models.json 中，将使用默认模型ID。")

    request_id = str(uuid.uuid4())
    response_channels[request_id] = asyncio.Queue()
    logger.info(f"API CALL [ID: {request_id[:8]}]: 已创建响应通道。")

    try:
        # 1. 转换请求
        lmarena_payload = convert_openai_to_lmarena_payload(openai_req, session_id, message_id)
        
        # 2. 包装成发送给浏览器的消息
        message_to_browser = {
            "request_id": request_id,
            "payload": lmarena_payload
        }
        
        # 3. 通过 WebSocket 发送
        logger.info(f"API CALL [ID: {request_id[:8]}]: 正在通过 WebSocket 发送载荷到油猴脚本。")
        await browser_ws.send_text(json.dumps(message_to_browser))

        # 4. 根据 stream 参数决定返回类型
        is_stream = openai_req.get("stream", True)

        if is_stream:
            # 返回流式响应
            return StreamingResponse(
                stream_generator(request_id, model_name or "default_model"),
                media_type="text/event-stream"
            )
        else:
            # 返回非流式响应
            return await non_stream_response(request_id, model_name or "default_model")
    except Exception as e:
        # 如果在设置过程中出错，清理通道
        if request_id in response_channels:
            del response_channels[request_id]
        logger.error(f"API CALL [ID: {request_id[:8]}]: 处理请求时发生致命错误: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail=str(e))

# --- 内部通信端点 ---
@app.post("/internal/start_id_capture")
async def start_id_capture():
    """
    接收来自 id_updater.py 的通知，并通过 WebSocket 指令
    激活油猴脚本的 ID 捕获模式。
    """
    if not browser_ws:
        logger.warning("ID CAPTURE: 收到激活请求，但没有浏览器连接。")
        raise HTTPException(status_code=503, detail="Browser client not connected.")
    
    try:
        logger.info("ID CAPTURE: 收到激活请求，正在通过 WebSocket 发送指令...")
        await browser_ws.send_text(json.dumps({"command": "activate_id_capture"}))
        logger.info("ID CAPTURE: 激活指令已成功发送。")
        return JSONResponse({"status": "success", "message": "Activation command sent."})
    except Exception as e:
        logger.error(f"ID CAPTURE: 发送激活指令时出错: {e}", exc_info=True)
        raise HTTPException(status_code=500, detail="Failed to send command via WebSocket.")


# --- 主程序入口 ---
if __name__ == "__main__":
    # 建议从 config.jsonc 中读取端口，此处为临时硬编码
    api_port = 5102
    logger.info(f"🚀 LMArena Bridge v2.0 API 服务器正在启动...")
    logger.info(f"   - 监听地址: http://127.0.0.1:{api_port}")
    logger.info(f"   - WebSocket 端点: ws://127.0.0.1:{api_port}/ws")
    
    uvicorn.run(app, host="0.0.0.0", port=api_port)
