// ==UserScript==
// @name         LMArena API Bridge
// @namespace    http://tampermonkey.net/
// @version      2.3
// @description  Bridges LMArena to a local API server via WebSocket for streamlined automation.
// @author       Lianues
// @match        https://lmarena.ai/*
// @match        https://*.lmarena.ai/*
// @icon         https://www.google.com/s2/favicons?sz=64&domain=lmarena.ai
// @grant        none
// @run-at       document-end
// ==/UserScript==

(function () {
    'use strict';

    // --- 配置 ---
    const SERVER_URL = "ws://localhost:5102/ws"; // 与 api_server.py 中的端口匹配
    let socket;
    let isCaptureModeActive = false; // ID捕获模式的开关

    // --- 核心逻辑 ---
    function connect() {
        console.log(`[API Bridge] 正在连接到本地服务器: ${SERVER_URL}...`);
        socket = new WebSocket(SERVER_URL);

        socket.onopen = () => {
            console.log("[API Bridge] ✅ 与本地服务器的 WebSocket 连接已建立。");
            document.title = "✅ " + document.title;
        };

        socket.onmessage = async (event) => {
            try {
                const message = JSON.parse(event.data);

                // 检查是否是指令，而不是标准的聊天请求
                if (message.command) {
                    console.log(`[API Bridge] ⬇️ 收到指令: ${message.command}`);
                    if (message.command === 'refresh' || message.command === 'reconnect') {
                        console.log(`[API Bridge] 收到 '${message.command}' 指令，正在执行页面刷新...`);
                        location.reload();
                    } else if (message.command === 'activate_id_capture') {
                        console.log("[API Bridge] ✅ ID 捕获模式已激活。请在页面上触发一次 'Retry' 操作。");
                        isCaptureModeActive = true;
                        // 可以选择性地给用户一个视觉提示
                        document.title = "🎯 " + document.title;
                    }
                    return;
                }

                const { request_id, payload } = message;

                if (!request_id || !payload) {
                    console.error("[API Bridge] 收到来自服务器的无效消息:", message);
                    return;
                }
                
                console.log(`[API Bridge] ⬇️ 收到聊天请求 ${request_id.substring(0, 8)}。准备执行 fetch 操作。`);
                await executeFetchAndStreamBack(request_id, payload);

            } catch (error) {
                console.error("[API Bridge] 处理服务器消息时出错:", error);
            }
        };

        socket.onclose = () => {
            console.warn("[API Bridge] 🔌 与本地服务器的连接已断开。将在5秒后尝试重新连接...");
            if (document.title.startsWith("✅ ")) {
                document.title = document.title.substring(2);
            }
            setTimeout(connect, 5000);
        };

        socket.onerror = (error) => {
            console.error("[API Bridge] ❌ WebSocket 发生错误:", error);
            socket.close(); // 会触发 onclose 中的重连逻辑
        };
    }

    async function executeFetchAndStreamBack(requestId, payload) {
        console.log(`[API Bridge] 当前操作域名: ${window.location.hostname}`);
        const { message_templates, target_model_id, session_id, message_id } = payload;

        // --- 使用从后端配置传递的会话信息 ---
        if (!session_id || !message_id) {
            const errorMsg = "从后端收到的会话信息 (session_id 或 message_id) 为空。请先运行 `id_updater.py` 脚本进行设置。";
            console.error(`[API Bridge] ${errorMsg}`);
            sendToServer(requestId, { error: errorMsg });
            sendToServer(requestId, "[DONE]");
            return;
        }

        const apiUrl = `/api/stream/retry-evaluation-session-message/${session_id}/messages/${message_id}`;
        console.log(`[API Bridge] 使用后端配置的 API 端点: ${apiUrl}`);

        // --- 新优化逻辑：将传入的最后一条消息设为 pending ---
        const newMessages = [];
        let lastMsgIdInChain = null;

        if (!message_templates || message_templates.length === 0) {
            const errorMsg = "从后端收到的消息列表为空。";
            console.error(`[API Bridge] ${errorMsg}`);
            sendToServer(requestId, { error: errorMsg });
            sendToServer(requestId, "[DONE]");
            return;
        }

        // 遍历所有消息，除了最后一条
        for (let i = 0; i < message_templates.length; i++) {
            const template = message_templates[i];
            const currentMsgId = crypto.randomUUID();
            const parentIds = lastMsgIdInChain ? [lastMsgIdInChain] : [];
            
            // 最后一条消息的状态设为 'pending'，其他都设为 'success'
            const status = (i === message_templates.length - 1) ? 'pending' : 'success';

            newMessages.push({
                role: template.role,
                content: template.content,
                id: currentMsgId,
                evaluationId: null,
                evaluationSessionId: session_id, // 使用从后端传递的 session_id
                parentMessageIds: parentIds,
                experimental_attachments: template.attachments || [],
                failureReason: null,
                metadata: null,
                // 使用从后端 payload 的 message_templates 中传递过来的 participantPosition
                participantPosition: template.participantPosition || "a",
                createdAt: new Date().toISOString(),
                updatedAt: new Date().toISOString(),
                status: status,
            });
            lastMsgIdInChain = currentMsgId;
        }

        const body = {
            messages: newMessages,
            modelId: target_model_id,
        };

        console.log("[API Bridge] 准备发送到 LMArena API 的最终载荷:", JSON.stringify(body, null, 2));

        // 设置一个标志，让我们的 fetch 拦截器知道这个请求是脚本自己发起的
        window.isApiBridgeRequest = true;
        try {
            const response = await fetch(apiUrl, {
                method: 'PUT', // 'retry' 端点使用 PUT 方法
                headers: {
                    'Content-Type': 'text/plain;charset=UTF-8', // LMArena 使用 text/plain
                    'Accept': '*/*',
                },
                body: JSON.stringify(body),
                credentials: 'include' // 必须包含 cookie
            });

            if (!response.ok || !response.body) {
                const errorBody = await response.text();
                throw new Error(`网络响应不正常。状态: ${response.status}. 内容: ${errorBody}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            while (true) {
                const { value, done } = await reader.read();
                if (done) {
                    console.log(`[API Bridge] ✅ 请求 ${requestId.substring(0, 8)} 的流已结束。`);
                    sendToServer(requestId, "[DONE]");
                    break;
                }
                const chunk = decoder.decode(value);
                // 直接将原始数据块转发回后端
                sendToServer(requestId, chunk);
            }

        } catch (error) {
            console.error(`[API Bridge] ❌ 在为请求 ${requestId.substring(0, 8)} 执行 fetch 时出错:`, error);
            sendToServer(requestId, { error: error.message });
            sendToServer(requestId, "[DONE]");
        } finally {
            // 请求结束后，无论成功与否，都重置标志
            window.isApiBridgeRequest = false;
        }
    }

    function sendToServer(requestId, data) {
        if (socket && socket.readyState === WebSocket.OPEN) {
            const message = {
                request_id: requestId,
                data: data
            };
            socket.send(JSON.stringify(message));
        } else {
            console.error("[API Bridge] 无法发送数据，WebSocket 连接未打开。");
        }
    }

    // --- 网络请求拦截 ---
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        const urlArg = args[0];
        let urlString = '';

        // 确保我们总是处理字符串形式的 URL
        if (urlArg instanceof Request) {
            urlString = urlArg.url;
        } else if (urlArg instanceof URL) {
            urlString = urlArg.href;
        } else if (typeof urlArg === 'string') {
            urlString = urlArg;
        }

        // 仅在 URL 是有效字符串时才进行匹配
        if (urlString) {
            const match = urlString.match(/\/api\/stream\/retry-evaluation-session-message\/([a-f0-9-]+)\/messages\/([a-f0-9-]+)/);

            // 仅在请求不是由API桥自身发起，且捕获模式已激活时，才更新ID
            if (match && !window.isApiBridgeRequest && isCaptureModeActive) {
                const sessionId = match[1];
                const messageId = match[2];
                console.log(`[API Bridge Interceptor] 🎯 在激活模式下捕获到ID！正在发送...`);

                // 关闭捕获模式，确保只发送一次
                isCaptureModeActive = false;
                if (document.title.startsWith("🎯 ")) {
                    document.title = document.title.substring(2);
                }

                // 异步将捕获到的ID发送到本地的 id_updater.py 脚本
                fetch('http://127.0.0.1:5103/update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sessionId, messageId })
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Server responded with status: ${response.status}`);
                    console.log(`[API Bridge] ✅ ID 更新成功发送。捕获模式已自动关闭。`);
                })
                .catch(err => {
                    console.error('[API Bridge] 发送ID更新时出错:', err.message);
                    // 即使发送失败，捕获模式也已关闭，不会重试。
                });
            }
        }

        // 调用原始的 fetch 函数，确保页面功能不受影响
        return originalFetch.apply(this, args);
    };


    // --- 页面加载后发送源码 ---
    function sendPageSourceAfterLoad() {
        const sendSource = async () => {
            console.log("[API Bridge] 页面加载完成。正在发送页面源码以供模型列表更新...");
            try {
                const htmlContent = document.documentElement.outerHTML;
                await fetch('http://localhost:5102/update_models', { // URL与api_server.py中的端点匹配
                    method: 'POST',
                    headers: {
                        'Content-Type': 'text/html; charset=utf-8'
                    },
                    body: htmlContent
                });
                 console.log("[API Bridge] 页面源码已成功发送。");
            } catch (e) {
                console.error("[API Bridge] 发送页面源码失败:", e);
            }
        };

        if (document.readyState === 'complete') {
            sendSource();
        } else {
            window.addEventListener('load', sendSource);
        }
    }


    // --- 启动连接 ---
    console.log("========================================");
    console.log("  LMArena API Bridge v2.1 正在运行。");
    console.log("  - 聊天功能已连接到 ws://localhost:5102");
    console.log("  - ID 捕获器将发送到 http://localhost:5103");
    console.log("========================================");
    
    sendPageSourceAfterLoad(); // 发送页面源码
    connect(); // 建立 WebSocket 连接

})();
