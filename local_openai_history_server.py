# local_openai_history_server.py
# v7.0 - OpenAI History Injection Ready

from flask import Flask, request, jsonify, Response
from flask_cors import CORS # 导入 CORS
from queue import Queue, Empty
import logging
import uuid
import threading
import time
import json
import re
from datetime import datetime, timezone
import os

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
# 构建 models.json 和 config.jsonc 的绝对路径
MODELS_JSON_PATH = os.path.join(SCRIPT_DIR, 'models.json')
CONFIG_JSONC_PATH = os.path.join(SCRIPT_DIR, 'config.jsonc')

# --- 配置 ---
log = logging.getLogger('werkzeug')
log.setLevel(logging.ERROR)
app = Flask(__name__)
CORS(app) # 为整个应用启用 CORS

# --- 数据存储 (与 v6.0 保持一致) ---
INJECTION_JOBS = Queue()
PROMPT_JOBS = Queue()
TOOL_RESULT_JOBS = Queue()
MODEL_FETCH_JOBS = Queue()
RESULTS = {}
REPORTED_MODELS_CACHE = {
    "data": None,
    "timestamp": 0,
    "event": threading.Event()
}

# --- 【新】注入完成信号 ---
INJECTION_EVENTS = {}


# --- 【新】模型自动更新逻辑 ---
def extract_models_from_html(html_content: str) -> list:
    """从 LMArena 页面的 HTML 内容中提取模型列表。"""
    # 正则表达式寻找包含模型列表的 'initialState' JSON 对象
    match = re.search(r'"initialState":(\[.*?\]),', html_content)
    if not match:
        # 尝试备用模式，处理转义后的引号
        match = re.search(r'initialState\\":(\[.*?\]),', html_content)
    
    if not match:
        print("ℹ️ [Model Updater] 在HTML内容中未找到 'initialState' 模型列表。")
        return []

    models_json_str = match.group(1)
    
    # 清理可能存在的转义字符
    if '\\"' in models_json_str:
        models_json_str = models_json_str.replace('\\"', '"')

    try:
        models_list = json.loads(models_json_str)
        extracted_models = []
        for model in models_list:
            if 'publicName' in model and 'id' in model:
                extracted_models.append({
                    'name': model['publicName'],
                    'id': model['id']
                })
        print(f"✅ [Model Updater] 从页面成功提取 {len(extracted_models)} 个模型。")
        return extracted_models
    except json.JSONDecodeError as e:
        print(f"❌ [Model Updater] 解析模型 JSON 失败: {e}")
        print(f"   > 问题片段: {models_json_str[:250]}...")
        return []

def update_models_json_file(new_models: list):
    """使用提取的新模型更新 models.json 文件。"""
    if not new_models:
        return

    try:
        # 【修改点 1】使用绝对路径变量 MODELS_JSON_PATH
        with open(MODELS_JSON_PATH, 'r+', encoding='utf-8') as f:
            try:
                existing_models = json.load(f)
            except json.JSONDecodeError:
                print(f"⚠️ [Model Updater] '{MODELS_JSON_PATH}' 文件已损坏或为空。将创建新内容。")
                existing_models = {}

            added_count = 0
            newly_added_names = []
            
            for model in new_models:
                model_name = model['name']
                model_id = model['id']
                if model_name not in existing_models:
                    existing_models[model_name] = model_id
                    added_count += 1
                    newly_added_names.append(model_name)

            if added_count > 0:
                print(f"✨ [Model Updater] 发现 {added_count} 个新模型！正在更新 '{MODELS_JSON_PATH}'...")
                for name in newly_added_names:
                    print(f"  -> 新增: {name}")
                
                f.seek(0)
                json.dump(existing_models, f, indent=4)
                f.truncate()
                print(f"✅ [Model Updater] '{MODELS_JSON_PATH}' 文件更新成功。")
            else:
                print(f"✅ [Model Updater] 检查完毕，所有模型均已存在于 '{MODELS_JSON_PATH}'。无需更新。")

    except FileNotFoundError:
        print(f"⚠️ [Model Updater] '{MODELS_JSON_PATH}' 文件未找到。正在创建新文件...")
        # 【修改点 2】使用绝对路径变量 MODELS_JSON_PATH
        with open(MODELS_JSON_PATH, 'w', encoding='utf-8') as f:
            models_to_write = {model['name']: model['id'] for model in new_models}
            json.dump(models_to_write, f, indent=4)
            print(f"✅ [Model Updater] 成功创建 '{MODELS_JSON_PATH}' 并添加了 {len(models_to_write)} 个模型。")


# --- 全局会话缓存 ---
LAST_CONVERSATION_STATE = None


# --- API 端点 ---

@app.route('/get_config', methods=['GET'])
def get_config():
    """读取并返回 config.jsonc 的内容，同时移除注释。"""
    try:
        # 【修改点】使用绝对路径变量 CONFIG_JSONC_PATH
        with open(CONFIG_JSONC_PATH, 'r', encoding='utf-8') as f:
            # 读取文件内容
            jsonc_content = f.read()
            # 移除单行和多行注释
            # 移除单行注释 // ...
            json_content = re.sub(r'//.*', '', jsonc_content)
            # 移除多行注释 /* ... */ (非贪婪模式)
            json_content = re.sub(r'/\*.*?\*/', '', json_content, flags=re.DOTALL)
            
            config_data = json.loads(json_content)
            return jsonify(config_data)
    except FileNotFoundError:
        print(f"❌ 错误: '{CONFIG_JSONC_PATH}' 文件未找到。")
        return jsonify({"error": "Config file not found"}), 404
    except json.JSONDecodeError:
        print(f"❌ 错误: '{CONFIG_JSONC_PATH}' 文件格式不正确。")
        return jsonify({"error": "Config file is malformed"}), 500

@app.route('/reset_state', methods=['POST'])
def reset_state():
    """手动重置会话缓存"""
    global LAST_CONVERSATION_STATE
    LAST_CONVERSATION_STATE = None
    print("🔄 [Cache] 会话缓存已被手动重置。")
    return jsonify({"status": "success", "message": "Conversation cache has been reset."})


@app.route('/')
def index():
    return "LMArena 自动化代理服务器 v8.0 (OpenAI History Injection Ready) 正在运行。"

# --- 模型映射表 ---
def load_model_map():
    """从 models.json 加载模型映射"""
    try:
        with open(MODELS_JSON_PATH, 'r', encoding='utf-8') as f:
            return json.load(f)
    except FileNotFoundError:
        print(f"❌ 错误: '{MODELS_JSON_PATH}' 文件未找到。请确保该文件存在。")
        return {}
    except json.JSONDecodeError:
        print(f"❌ 错误: '{MODELS_JSON_PATH}' 文件格式不正确。")
        return {}

MODEL_NAME_TO_ID_MAP = load_model_map()
DEFAULT_MODEL_ID = "f44e280a-7914-43ca-a25d-ecfcc5d48d09" # 默认 Claude 3.5 Sonnet

# --- 格式转换逻辑 (v2) ---
def convert_openai_to_lmarena(openai_data):
    """将 OpenAI 格式的对话历史转换为 LMArena 内部格式，并注入正确的模型 ID"""
    session_id = f"c{str(uuid.uuid4())[1:]}"
    user_id = f"u{str(uuid.uuid4())[1:]}"
    evaluation_id = f"e{str(uuid.uuid4())[1:]}"
    
    # 根据模型名称查找模型 ID
    model_name = openai_data.get("model", "claude-3-5-sonnet-20241022")
    target_model_id = MODEL_NAME_TO_ID_MAP.get(model_name, DEFAULT_MODEL_ID)
    print(f"🤖 模型映射: '{model_name}' -> '{target_model_id}'")

    lmarena_messages = []
    parent_msg_id = None

    for i, oai_msg in enumerate(openai_data["messages"]):
        msg_id = str(uuid.uuid4())
        created_at = datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')
        
        lm_msg = {
            "id": msg_id,
            "evaluationSessionId": session_id,
            "evaluationId": evaluation_id,
            "parentMessageIds": [parent_msg_id] if parent_msg_id else [],
            "content": oai_msg.get("content", ""),
            "modelId": None if oai_msg["role"] in ("user", "system") else target_model_id, # 用户和系统消息不指定模型ID
            "status": "success",
            "failureReason": None,
            "metadata": None,
            "createdAt": created_at,
            "updatedAt": created_at,
            "role": oai_msg["role"],
            "experimental_attachments": [],
            "participantPosition": "a"
        }
        lmarena_messages.append(lm_msg)
        parent_msg_id = msg_id

    title = "New Conversation"
    if openai_data["messages"]:
        title = openai_data["messages"][0].get("content", "New Conversation")[:50]

    history_data = {
        "id": session_id,
        "userId": user_id,
        "title": title,
        "mode": "direct",
        "visibility": "public",
        "lastMessageIds": [parent_msg_id] if parent_msg_id else [],
        "createdAt": datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
        "updatedAt": datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z'),
        "messages": lmarena_messages,
        "pairwiseFeedbacks": [],
        "pointwiseFeedbacks": [],
        "maskedEvaluations": [
            {
                "id": evaluation_id,
                "modality": "chat",
                "arenaId": "4c249f58-2f34-4859-bbdb-4233a8313340"
            }
        ],
        # 【【【核心新增】】】将目标模型 ID 传递给油猴脚本
        "targetModelId": target_model_id
    }
    return history_data

# --- 【【【新】】】OpenAI 格式历史注入 API (已升级) ---
@app.route('/inject_openai_history', methods=['POST'])
def inject_openai_history():
    """接收 OpenAI 格式的历史，转换后放入注入队列"""
    openai_job_data = request.json
    if not openai_job_data or 'messages' not in openai_job_data:
        return jsonify({"status": "error", "message": "请求体需要包含 'messages' 字段。"}), 400
    
    print("🔄 接收到 OpenAI 格式注入任务，开始转换为 LMarena 格式...")
    lmarena_job_data = convert_openai_to_lmarena(openai_job_data)
    
    INJECTION_JOBS.put(lmarena_job_data)
    print(f"✅ 转换完成！已将【LMarena 格式任务】放入注入队列。队列现有任务: {INJECTION_JOBS.qsize()}。")
    return jsonify({"status": "success", "message": "OpenAI history converted and submitted"}), 200


# --- 原有注入 API (保持功能，用于向后兼容或特定场景) ---
@app.route('/submit_injection_job', methods=['POST'])
def submit_injection_job():
    job_data = request.json
    INJECTION_JOBS.put(job_data)
    print(f"✅ 已接收到新的【标准注入任务】。注入队列现有任务: {INJECTION_JOBS.qsize()}。")
    return jsonify({"status": "success", "message": "Injection job submitted"}), 200

@app.route('/get_injection_job', methods=['GET'])
def get_injection_job():
    try:
        job = INJECTION_JOBS.get_nowait()
        print(f"🚀 Automator 脚本已取走注入任务。队列剩余: {INJECTION_JOBS.qsize()}。")
        return jsonify({"status": "success", "job": job}), 200
    except Empty:
        return jsonify({"status": "empty"}), 200

@app.route('/signal_injection_complete', methods=['POST'])
def signal_injection_complete():
    """接收油猴脚本的注入完成信号，并可选择性地处理页面内容以更新模型。"""
    data = request.json
    injection_id = data.get('injection_id')
    html_content = data.get('page_html')  # 接收可选的 HTML 内容

    # 如果接收到 HTML 内容，则触发模型更新流程
    if html_content:
        print("ℹ️ [Model Updater] 接收到页面 HTML，开始自动更新模型库...")
        extracted_models = extract_models_from_html(html_content)
        update_models_json_file(extracted_models)

    # 兼容仅更新模型而不处理注入信号的情况
    if not injection_id:
        if html_content:
            return jsonify({"status": "success", "message": "Models updated, no injection ID provided."}), 200
        return jsonify({"status": "error", "message": "需要 'injection_id' 字段。"}), 400

    if injection_id in INJECTION_EVENTS:
        INJECTION_EVENTS[injection_id].set()  # 触发事件
        del INJECTION_EVENTS[injection_id]  # 清理
        print(f"✔️ 接收到注入任务 {injection_id} 的完成信号。")
        return jsonify({"status": "success"}), 200
    else:
        print(f"⚠️ 接收到未知或已过期的注入任务信号: {injection_id}")
        return jsonify({"status": "error", "message": "未知的注入 ID。"}), 404


# --- 交互式对话 API ---
@app.route('/submit_prompt', methods=['POST'])
def submit_prompt():
    data = request.json
    if not data or 'prompt' not in data:
        return jsonify({"status": "error", "message": "需要 'prompt' 字段。"}), 400
    
    task_id = str(uuid.uuid4())
    # 确保将 task_id 包含在任务数据中
    job = {"task_id": task_id, "prompt": data['prompt']}
    PROMPT_JOBS.put(job)
    
    # 为这个新任务初始化结果存储，这是接收流式响应所必需的
    RESULTS[task_id] = {
        "status": "pending",
        "stream_queue": Queue(),
        "full_response": None
    }
    
    print(f"✅ 已接收到新的【对话任务】(ID: {task_id[:8]})。对话队列现有任务: {PROMPT_JOBS.qsize()}。")
    return jsonify({"status": "success", "task_id": task_id}), 200

@app.route('/get_prompt_job', methods=['GET'])
def get_prompt_job():
    try:
        job = PROMPT_JOBS.get_nowait()
        print(f"🚀 Automator 脚本已取走对话任务 (ID: {job['task_id'][:8]})。队列剩余: {PROMPT_JOBS.qsize()}。")
        return jsonify({"status": "success", "job": job}), 200
    except Empty:
        return jsonify({"status": "empty"}), 200

# --- 流式数据 API (无变化) ---
@app.route('/stream_chunk', methods=['POST'])
def stream_chunk():
    data = request.json
    task_id = data.get('task_id')
    chunk = data.get('chunk')
    if task_id in RESULTS:
        RESULTS[task_id]['stream_queue'].put(chunk)
        return jsonify({"status": "success"}), 200
    return jsonify({"status": "error", "message": "无效的任务 ID"}), 404

@app.route('/get_chunk/<task_id>', methods=['GET'])
def get_chunk(task_id):
    if task_id in RESULTS:
        try:
            chunk = RESULTS[task_id]['stream_queue'].get_nowait()
            return jsonify({"status": "ok", "chunk": chunk}), 200
        except Empty:
            if RESULTS[task_id]['status'] in ['completed', 'failed']:
                return jsonify({"status": "done"}), 200
            else:
                return jsonify({"status": "empty"}), 200
    return jsonify({"status": "not_found"}), 404
    
@app.route('/report_result', methods=['POST'])
def report_result():
    data = request.json
    task_id = data.get('task_id')
    if task_id and task_id in RESULTS:
        RESULTS[task_id]['status'] = data.get('status', 'completed')
        RESULTS[task_id]['full_response'] = data.get('content', '')
        print(f"✔️ 任务 {task_id[:8]} 已完成。状态: {RESULTS[task_id]['status']}。")
        return jsonify({"status": "success"}), 200
    return jsonify({"status": "error", "message": "无效的任务 ID。"}), 404

# --- 工具函数结果 API (无变化) ---
@app.route('/submit_tool_result', methods=['POST'])
def submit_tool_result():
    data = request.json
    if not data or 'task_id' not in data or 'result' not in data:
        return jsonify({"status": "error", "message": "需要 'task_id' 和 'result' 字段。"}), 400
    
    task_id = data['task_id']
    job = {"task_id": task_id, "result": data['result']}
    TOOL_RESULT_JOBS.put(job)
    RESULTS[task_id] = {
        "status": "pending",
        "stream_queue": Queue(),
        "full_response": None
    }
    print(f"✅ 已接收到新的【工具返回任务】(ID: {task_id[:8]})。工具队列现有任务: {TOOL_RESULT_JOBS.qsize()}。")
    return jsonify({"status": "success"}), 200

@app.route('/get_tool_result_job', methods=['GET'])
def get_tool_result_job():
    try:
        job = TOOL_RESULT_JOBS.get_nowait()
        print(f"🚀 Automator 已取走工具返回任务 (ID: {job['task_id'][:8]})。队列剩余: {TOOL_RESULT_JOBS.qsize()}。")
        return jsonify({"status": "success", "job": job}), 200
    except Empty:
        return jsonify({"status": "empty"}), 200

# --- 模型获取 API (无变化) ---
@app.route('/submit_model_fetch_job', methods=['POST'])
def submit_model_fetch_job():
    if not MODEL_FETCH_JOBS.empty():
        return jsonify({"status": "success", "message": "A fetch job is already pending."}), 200
    
    task_id = str(uuid.uuid4())
    job = {"task_id": task_id, "type": "FETCH_MODELS"}
    MODEL_FETCH_JOBS.put(job)
    REPORTED_MODELS_CACHE['event'].clear()
    REPORTED_MODELS_CACHE['data'] = None
    print(f"✅ 已接收到新的【模型获取任务】(ID: {task_id[:8]})。")
    return jsonify({"status": "success", "task_id": task_id})

@app.route('/get_model_fetch_job', methods=['GET'])
def get_model_fetch_job():
    try:
        job = MODEL_FETCH_JOBS.queue[0]
        return jsonify({"status": "success", "job": job}), 200
    except IndexError:
        return jsonify({"status": "empty"}), 200

@app.route('/acknowledge_model_fetch_job', methods=['POST'])
def acknowledge_model_fetch_job():
    try:
        job = MODEL_FETCH_JOBS.get_nowait()
        print(f"🚀 Model Fetcher 已确认并取走模型获取任务 (ID: {job['task_id'][:8]})。")
        return jsonify({"status": "success"}), 200
    except Empty:
        return jsonify({"status": "error", "message": "No job to acknowledge."}), 400

@app.route('/report_models', methods=['POST'])
def report_models():
    data = request.json
    models_json = data.get('models_json')
    if models_json:
        REPORTED_MODELS_CACHE['data'] = models_json
        REPORTED_MODELS_CACHE['timestamp'] = uuid.uuid4().int
        REPORTED_MODELS_CACHE['event'].set()
        print(f"✔️ 成功接收并缓存了新的模型列表数据。")
        return jsonify({"status": "success"}), 200
    return jsonify({"status": "error", "message": "需要 'models_json' 字段。"}), 400

@app.route('/get_reported_models', methods=['GET'])
def get_reported_models():
    wait_result = REPORTED_MODELS_CACHE['event'].wait(timeout=60)
    if not wait_result:
        return jsonify({"status": "error", "message": "等待模型数据超时 (60 秒)。"}), 408
    if REPORTED_MODELS_CACHE['data']:
        return jsonify({
            "status": "success",
            "data": REPORTED_MODELS_CACHE['data'],
            "timestamp": REPORTED_MODELS_CACHE['timestamp']
        }), 200
    else:
        return jsonify({"status": "error", "message": "数据获取失败，即使事件已触发。"}), 500


# --- 【【【新】】】OpenAI 兼容 API ---

def format_openai_chunk(content: str, model: str, request_id: str):
    """格式化 OpenAI 流式响应的文本块"""
    chunk_data = {
        "id": request_id,
        "object": "chat.completion.chunk",
        "created": int(time.time()),
        "model": model,
        "choices": [{"index": 0, "delta": {"content": content}, "finish_reason": None}]
    }
    return f"data: {json.dumps(chunk_data)}\n\n"

def format_openai_finish_chunk(model: str, request_id: str, finish_reason: str = "stop"):
    """格式化 OpenAI 流式响应的结束块"""
    chunk_data = {
        "id": request_id,
        "object": "chat.completion.chunk",
        "created": int(time.time()),
        "model": model,
        "choices": [{"index": 0, "delta": {}, "finish_reason": finish_reason}]
    }
    return f"data: {json.dumps(chunk_data)}\n\n"

def format_openai_non_stream_response(content: str, model: str, request_id: str, finish_reason: str = "stop"):
    """格式化 OpenAI 非流式响应"""
    response_data = {
        "id": request_id,
        "object": "chat.completion",
        "created": int(time.time()),
        "model": model,
        "choices": [{
            "index": 0,
            "message": {"role": "assistant", "content": content},
            "finish_reason": finish_reason
        }],
        "usage": {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0}
    }
    return response_data

def _normalize_message_content(message: dict) -> dict:
    """
    确保消息内容是字符串，处理 OpenAI 客户端可能发送的 content 列表。
    """
    content = message.get("content")
    # 仅当 content 是列表时才进行处理
    if isinstance(content, list):
        # 将所有 text 部分连接起来
        message["content"] = "\n\n".join(
            [p.get("text", "") for p in content if isinstance(p, dict) and p.get("type") == "text"]
        )
    return message

def _openai_response_generator(task_id: str):
    """
    一个生成器，从内部队列中拉取结果块，解析并提取纯文本内容。
    这个生成器是流式和非流式响应的基础。
    """
    # 正则表达式用于从原始数据块中提取 "a0:..." 格式的文本内容
    text_pattern = re.compile(r'a0:"((?:\\.|[^"\\])*)"')

    while True:
        try:
            # 从内部队列获取下一个原始数据块
            raw_chunk = RESULTS[task_id]['stream_queue'].get(timeout=1)
            
            # 从原始流中提取 a0:"..." 的内容
            matches = text_pattern.findall(raw_chunk)
            for match in matches:
                # 使用 json.loads 来正确处理可能的转义字符 (e.g., \n, \")
                try:
                    text_content = json.loads(f'"{match}"')
                    if text_content: # 确保不 yield 空字符串
                        yield text_content
                except json.JSONDecodeError:
                    # 如果解析失败，跳过这个匹配项
                    continue

        except Empty:
            # 检查任务是否已由 Automator 脚本标记为完成
            if RESULTS.get(task_id, {}).get('status') in ['completed', 'failed']:
                return # 结束生成器

def _update_conversation_state(request_base, new_messages: list):
    """
    通用状态更新函数。
    - request_base: 不包含新消息的基础请求。
    - new_messages: 一个包含 'user' 和 'assistant' 消息的列表。
    """
    global LAST_CONVERSATION_STATE
    new_state = request_base.copy()
    if "messages" not in new_state:
        new_state["messages"] = []
    
    # 【【【新：过滤占位消息】】】在更新缓存前，过滤掉我们自己添加的占位消息
    final_messages_to_add = [
        msg for msg in new_messages
        if not (msg.get("role") == "user" and msg.get("content", "").strip() == "")
    ]

    new_state["messages"].extend(final_messages_to_add)
    LAST_CONVERSATION_STATE = new_state
    print(f"✅ [Cache] 会话状态已更新，当前共 {len(new_state['messages'])} 条消息。")

@app.route('/v1/models', methods=['GET'])
def list_models():
    """兼容 OpenAI 的 /v1/models 端点，返回 models.json 中的模型列表。"""
    print("🔄 [API] 接收到 /v1/models 请求...")
    model_map = load_model_map()
    if not model_map:
        return jsonify({"error": "无法加载 'models.json'。"}), 500

    openai_models = []
    # The client uses the 'name' (e.g., 'claude-3-5-sonnet-20241022') as the model ID in requests.
    for model_name in model_map.keys():
        openai_models.append({
            "id": model_name,
            "object": "model",
            "created": int(time.time()),
            "owned_by": "local-history-server"
        })

    response_data = {
      "object": "list",
      "data": openai_models
    }
    
    return jsonify(response_data)


@app.route('/v1/chat/completions', methods=['POST'])
def chat_completions():
    """
    兼容 OpenAI 的 chat completions 端点（带会话缓存）。
    """
    global LAST_CONVERSATION_STATE
    request_data = request.json
    if not request_data or "messages" not in request_data:
        return jsonify({"error": "请求体需要包含 'messages' 字段。"}), 400

    try:
        # 在进行任何处理之前，先规范化消息内容
        messages = [_normalize_message_content(msg) for msg in request_data.get("messages", [])]
        # 【【【核心修复】】】用规范化后的消息列表更新原始请求数据
        request_data["messages"] = messages
    except Exception as e:
        return jsonify({"error": f"处理消息内容时失败: {e}"}), 400

    if not messages:
        return jsonify({"error": "'messages' 列表不能为空。"}), 400

    model = request_data.get("model", "claude-3-5-sonnet-20241022")
    use_stream = request_data.get("stream", False)
    request_id = f"chatcmpl-{uuid.uuid4()}"

    # --- 对话连续性检测 ---
    is_continuation = False
    if LAST_CONVERSATION_STATE:
        cached_messages = LAST_CONVERSATION_STATE.get("messages", [])
        new_messages_base = messages[:-1]
        if json.dumps(cached_messages, sort_keys=True) == json.dumps(new_messages_base, sort_keys=True):
            is_continuation = True

    last_message = messages[-1]
    prompt_content = last_message.get("content", "")
    request_base_for_update = request_data.copy()
    request_base_for_update["messages"] = messages[:-1]

    # --- 路径选择：快速通道 vs 完整注入 ---
    if is_continuation:
        print(f"⚡️ [Fast Path] 检测到连续对话 (请求 {request_id[:8]})，跳过历史注入。")
    else:
        print(f"🔄 [Full Injection] 检测到新对话或状态不一致 (请求 {request_id[:8]})，执行完整历史注入。")
        LAST_CONVERSATION_STATE = None # 重置状态
        
        # 准备要注入的历史记录 (除最后一条消息外的所有内容)
        history_messages = messages[:-1]
        
        # 【【【核心重构：使用事件信号机制替代 time.sleep】】】
        injection_id = str(uuid.uuid4())
        
        # 1. 先创建并存储事件
        event = threading.Event()
        INJECTION_EVENTS[injection_id] = event
        print(f"  > 已为注入任务 {injection_id} 创建等待信号。")

        # 2. 准备任务并放入队列
        history_messages = messages[:-1]
        
        # 【【【核心修复：处理空历史和系统提示词】】】
        # 如果历史记录为空，我们需要决定注入什么。
        if not history_messages:
            # 查找原始请求中是否有 system prompt
            system_prompt = next((msg for msg in messages if msg['role'] == 'system'), None)
            if system_prompt:
                print("  > 检测到空历史记录，但有系统提示词。将注入系统提示词。")
                history_messages.append(system_prompt)
            else:
                print("  > 检测到空历史记录，且无系统提示词。注入一个带空格的系统提示词以初始化。")
                history_messages.append({"role": "system", "content": " "})

        history_data = {"model": model, "messages": history_messages}
        lmarena_history_job = convert_openai_to_lmarena(history_data)
        lmarena_history_job["injection_id"] = injection_id
        INJECTION_JOBS.put(lmarena_history_job)
        print(f"  > 已提交注入任务 {injection_id}。等待油猴脚本的完成信号...")

        # 3. 现在可以安全地等待事件了
        completed_in_time = event.wait(timeout=60.0)
        if completed_in_time:
            print(f"  > 注入任务 {injection_id} 已确认完成。继续执行。")
        else:
            print(f"  > 警告：等待注入任务 {injection_id} 完成超时（60秒）。可能出现问题。")
            # 超时后也清理掉事件，避免内存泄漏
            if injection_id in INJECTION_EVENTS:
                del INJECTION_EVENTS[injection_id]

    # --- 任务提交与响应生成 ---
    task_id = str(uuid.uuid4())
    prompt_job = {"task_id": task_id, "prompt": prompt_content}
    PROMPT_JOBS.put(prompt_job)
    RESULTS[task_id] = {"status": "pending", "stream_queue": Queue(), "full_response": None}
    print(f"✅ 已为请求 {request_id[:8]} 创建新的对话任务 (ID: {task_id[:8]})。")

    if use_stream:
        def stream_response():
            print(f"🟢 开始为请求 {request_id[:8]} (任务ID: {task_id[:8]}) 进行流式传输...")
            
            full_ai_response_text = []
            # 直接迭代生成器，实现真正的流式传输
            for chunk in _openai_response_generator(task_id):
                full_ai_response_text.append(chunk)
                yield format_openai_chunk(chunk, model, request_id)
            
            # 流结束后，组合完整响应并更新会话状态
            final_text = "".join(full_ai_response_text)
            assistant_message = {"role": "assistant", "content": final_text}
            _update_conversation_state(request_base_for_update, [last_message, assistant_message])
            
            # 发送结束信号
            yield format_openai_finish_chunk(model, request_id)
            yield "data: [DONE]\n\n"
            print(f"🟡 请求 {request_id[:8]} (任务ID: {task_id[:8]}) 流式传输结束。")

        return Response(stream_response(), mimetype='text/event-stream')
    else:
        # 非流式响应
        print(f"🟢 开始为请求 {request_id[:8]} (任务ID: {task_id[:8]}) 在后台收集响应...")
        
        full_response_content = "".join(list(_openai_response_generator(task_id)))
        
        # 更新会话状态
        assistant_message = {"role": "assistant", "content": full_response_content}
        _update_conversation_state(request_base_for_update, [last_message, assistant_message])

        final_json = format_openai_non_stream_response(full_response_content, model, request_id)
        print(f"🟡 请求 {request_id[:8]} (任务ID: {task_id[:8]}) 响应收集完成。")
        return jsonify(final_json)


if __name__ == '__main__':
    print("======================================================================")
    print("  LMArena 自动化代理服务器 v8.0 (OpenAI API Ready)")
    print("  ✨ 新增: /v1/chat/completions (兼容 OpenAI 的对话接口)")
    print("  ✨ 新增: /inject_openai_history (用于 OpenAI 格式历史注入)")
    print("  ✨ 新增: /submit_prompt, /get_prompt_job (用于发起对话)")
    print("  ✨ 新增: /stream_chunk, /get_chunk, /report_result (用于流式传输)")
    print("  - /submit_injection_job, /get_injection_job (标准注入)")
    print("  - /submit_tool_result, /get_tool_result_job (返回工具结果)")
    print("  - /submit_model_fetch_job, /get_model_fetch_job (获取模型)")
    print("  已在 http://127.0.0.1:5102 启动")
    print("======================================================================")
    app.run(host='0.0.0.0', port=5102, threaded=True)
