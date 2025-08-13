// ==UserScript==
// @name         LMArena Bridge - Distributed Client
// @namespace    http://tampermonkey.net/
// @version      3.0
// @description  Enhanced browser automation for distributed LMArena Bridge API system
// @author       LMArena Bridge Team
// @match        https://lmarena.ai/*
// @match        https://*.lmarena.ai/*
// @icon         https://www.google.com/s2/favicons?sz=64&domain=lmarena.ai
// @grant        none
// @run-at       document-end
// ==/UserScript==

(function () {
    'use strict';

    // Configuration
    const CONFIG = {
        PYTHON_CLIENT_URL: "http://localhost:8080", // Python client local server
        HEARTBEAT_INTERVAL: 30000, // 30 seconds
        REQUEST_TIMEOUT: 300000, // 5 minutes
        MAX_CONCURRENT_REQUESTS: 5,
        DEBUG: true
    };

    // Global state
    let isConnected = false;
    let activeRequests = new Map();
    let requestQueue = [];
    let processingCount = 0;
    let sessionInfo = null;
    let heartbeatInterval = null;

    // Logging utility
    function log(level, message, data = null) {
        const timestamp = new Date().toISOString();
        const prefix = `[LMArena Bridge ${level.toUpperCase()}] ${timestamp}`;
        
        if (CONFIG.DEBUG || level === 'error') {
            console.log(`${prefix}: ${message}`, data || '');
        }
    }

    // Initialize the script
    function initialize() {
        log('info', 'Initializing LMArena Bridge Enhanced Script v3.0');
        
        // Extract session information from page
        extractSessionInfo();
        
        // Start connection to Python client
        connectToPythonClient();
        
        // Setup request interception
        setupRequestInterception();
        
        // Start heartbeat
        startHeartbeat();
        
        // Setup page visibility handling
        setupVisibilityHandling();
        
        log('info', 'Initialization complete');
    }

    // Extract session information from the current page
    function extractSessionInfo() {
        try {
            // Try to extract session info from various sources
            const urlParams = new URLSearchParams(window.location.search);
            const sessionId = urlParams.get('session_id') || extractSessionFromDOM();
            const messageId = extractMessageIdFromDOM();
            
            sessionInfo = {
                sessionId: sessionId,
                messageId: messageId,
                url: window.location.href,
                timestamp: Date.now()
            };
            
            log('info', 'Session info extracted', sessionInfo);
        } catch (error) {
            log('error', 'Failed to extract session info', error);
        }
    }

    // Extract session ID from DOM elements
    function extractSessionFromDOM() {
        // Look for session ID in various DOM elements
        const selectors = [
            '[data-session-id]',
            '[data-conversation-id]',
            '.session-info',
            '#session-id'
        ];
        
        for (const selector of selectors) {
            const element = document.querySelector(selector);
            if (element) {
                return element.dataset.sessionId || element.dataset.conversationId || element.textContent;
            }
        }
        
        // Try to extract from URL or other sources
        const match = window.location.href.match(/session[_-]?id[=:]([a-f0-9-]+)/i);
        return match ? match[1] : null;
    }

    // Extract message ID from DOM
    function extractMessageIdFromDOM() {
        // Similar logic for message ID extraction
        const selectors = [
            '[data-message-id]',
            '.message-id',
            '#message-id'
        ];
        
        for (const selector of selectors) {
            const element = document.querySelector(selector);
            if (element) {
                return element.dataset.messageId || element.textContent;
            }
        }
        
        return null;
    }

    // Connect to Python client
    async function connectToPythonClient() {
        try {
            const response = await fetch(`${CONFIG.PYTHON_CLIENT_URL}/register`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    browser_info: {
                        userAgent: navigator.userAgent,
                        url: window.location.href,
                        sessionInfo: sessionInfo
                    },
                    capabilities: {
                        maxConcurrentRequests: CONFIG.MAX_CONCURRENT_REQUESTS,
                        supportedFeatures: ['streaming', 'multimodal', 'conversation_history']
                    }
                })
            });

            if (response.ok) {
                isConnected = true;
                log('info', 'Connected to Python client successfully');
                startRequestPolling();
            } else {
                throw new Error(`Connection failed: ${response.status}`);
            }
        } catch (error) {
            log('error', 'Failed to connect to Python client', error);
            // Retry connection after delay
            setTimeout(connectToPythonClient, 5000);
        }
    }

    // Start polling for requests from Python client
    function startRequestPolling() {
        if (!isConnected) return;

        const poll = async () => {
            try {
                const response = await fetch(`${CONFIG.PYTHON_CLIENT_URL}/poll`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    if (data.requests && data.requests.length > 0) {
                        for (const request of data.requests) {
                            await processRequest(request);
                        }
                    }
                }
            } catch (error) {
                log('error', 'Polling error', error);
            }

            // Schedule next poll
            if (isConnected) {
                const delay = processingCount >= CONFIG.MAX_CONCURRENT_REQUESTS ? 2000 : 1000;
                setTimeout(poll, delay);
            }
        };

        poll();
    }

    // Process a request from the Python client
    async function processRequest(request) {
        if (processingCount >= CONFIG.MAX_CONCURRENT_REQUESTS) {
            log('warn', 'Max concurrent requests reached, queuing request', request.id);
            requestQueue.push(request);
            return;
        }

        const requestId = request.id;
        log('info', `Processing request ${requestId}`, request);

        processingCount++;
        activeRequests.set(requestId, {
            ...request,
            startTime: Date.now(),
            status: 'processing'
        });

        try {
            await executeRequest(request);
        } catch (error) {
            log('error', `Request ${requestId} failed`, error);
            await sendError(requestId, error.message);
        } finally {
            processingCount--;
            activeRequests.delete(requestId);
            
            // Process queued requests
            if (requestQueue.length > 0 && processingCount < CONFIG.MAX_CONCURRENT_REQUESTS) {
                const nextRequest = requestQueue.shift();
                processRequest(nextRequest);
            }
        }
    }

    // Execute the actual request against LMArena
    async function executeRequest(request) {
        const { id: requestId, model, messages, stream = true } = request;
        
        // Prepare the request payload for LMArena
        const payload = {
            model: model,
            messages: messages,
            stream: stream,
            // Add other parameters as needed
        };

        // Mark request as API bridge request to avoid interception
        window.isApiBridgeRequest = true;

        try {
            const response = await fetch('/api/chat/completions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Request-ID': requestId
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(`LMArena API error: ${response.status}`);
            }

            if (stream) {
                await handleStreamingResponse(requestId, response);
            } else {
                await handleNonStreamingResponse(requestId, response);
            }

        } finally {
            window.isApiBridgeRequest = false;
        }
    }

    // Handle streaming response from LMArena
    async function handleStreamingResponse(requestId, response) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let sequence = 0;
        let fullResponse = '';

        try {
            while (true) {
                const { done, value } = await reader.read();
                
                if (done) break;

                const chunk = decoder.decode(value, { stream: true });
                const lines = chunk.split('\n');

                for (const line of lines) {
                    if (line.startsWith('data: ')) {
                        const data = line.slice(6);
                        
                        if (data === '[DONE]') {
                            await sendCompletion(requestId, fullResponse);
                            return;
                        }

                        try {
                            const parsed = JSON.parse(data);
                            const content = parsed.choices?.[0]?.delta?.content || '';
                            
                            if (content) {
                                fullResponse += content;
                                await sendChunk(requestId, content, sequence++);
                            }
                        } catch (parseError) {
                            log('warn', 'Failed to parse streaming data', parseError);
                        }
                    }
                }
            }
        } catch (error) {
            throw new Error(`Streaming error: ${error.message}`);
        }
    }

    // Handle non-streaming response from LMArena
    async function handleNonStreamingResponse(requestId, response) {
        const data = await response.json();
        const content = data.choices?.[0]?.message?.content || '';
        await sendCompletion(requestId, content);
    }

    // Send response chunk to Python client
    async function sendChunk(requestId, content, sequence) {
        try {
            await fetch(`${CONFIG.PYTHON_CLIENT_URL}/response`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    request_id: requestId,
                    type: 'chunk',
                    content: content,
                    sequence: sequence
                })
            });
        } catch (error) {
            log('error', 'Failed to send chunk', error);
        }
    }

    // Send completion notification to Python client
    async function sendCompletion(requestId, fullResponse) {
        const request = activeRequests.get(requestId);
        const responseTime = request ? Date.now() - request.startTime : 0;

        try {
            await fetch(`${CONFIG.PYTHON_CLIENT_URL}/response`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    request_id: requestId,
                    type: 'complete',
                    full_response: fullResponse,
                    response_time_ms: responseTime
                })
            });
            
            log('info', `Request ${requestId} completed in ${responseTime}ms`);
        } catch (error) {
            log('error', 'Failed to send completion', error);
        }
    }

    // Send error notification to Python client
    async function sendError(requestId, errorMessage) {
        try {
            await fetch(`${CONFIG.PYTHON_CLIENT_URL}/response`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    request_id: requestId,
                    type: 'error',
                    error_message: errorMessage,
                    error_type: 'processing_error'
                })
            });
        } catch (error) {
            log('error', 'Failed to send error', error);
        }
    }

    // Setup request interception for session ID capture
    function setupRequestInterception() {
        const originalFetch = window.fetch;
        
        window.fetch = function(...args) {
            const [url, options] = args;
            
            // Capture session information from outgoing requests
            if (url.includes('/api/') && !window.isApiBridgeRequest) {
                try {
                    const urlObj = new URL(url, window.location.origin);
                    const sessionMatch = urlObj.pathname.match(/\/([a-f0-9-]{36})\/([a-f0-9-]{36})/);
                    
                    if (sessionMatch) {
                        sessionInfo = {
                            ...sessionInfo,
                            sessionId: sessionMatch[1],
                            messageId: sessionMatch[2],
                            lastUpdated: Date.now()
                        };
                        
                        log('info', 'Updated session info from request', sessionInfo);
                    }
                } catch (error) {
                    log('warn', 'Failed to extract session from request', error);
                }
            }
            
            return originalFetch.apply(this, args);
        };
    }

    // Start heartbeat to Python client
    function startHeartbeat() {
        heartbeatInterval = setInterval(async () => {
            if (!isConnected) return;

            try {
                await fetch(`${CONFIG.PYTHON_CLIENT_URL}/heartbeat`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        timestamp: Date.now(),
                        active_requests: activeRequests.size,
                        queue_length: requestQueue.length,
                        session_info: sessionInfo
                    })
                });
            } catch (error) {
                log('error', 'Heartbeat failed', error);
                isConnected = false;
                // Try to reconnect
                setTimeout(connectToPythonClient, 5000);
            }
        }, CONFIG.HEARTBEAT_INTERVAL);
    }

    // Setup page visibility handling
    function setupVisibilityHandling() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                log('info', 'Page hidden, reducing activity');
            } else {
                log('info', 'Page visible, resuming normal activity');
                // Refresh session info when page becomes visible
                extractSessionInfo();
            }
        });
    }

    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
        }
        
        // Notify Python client of disconnect
        if (isConnected) {
            navigator.sendBeacon(`${CONFIG.PYTHON_CLIENT_URL}/disconnect`, 
                JSON.stringify({ timestamp: Date.now() }));
        }
    });

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    // Expose global functions for debugging
    window.LMArenabridge = {
        getStatus: () => ({
            connected: isConnected,
            activeRequests: activeRequests.size,
            queueLength: requestQueue.length,
            sessionInfo: sessionInfo
        }),
        reconnect: connectToPythonClient,
        extractSession: extractSessionInfo
    };

    log('info', 'LMArena Bridge Enhanced Script loaded successfully');

})();
