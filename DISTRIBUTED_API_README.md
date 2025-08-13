# 🚀 LMArena Bridge - Distributed API Proxy System

A complete enterprise-grade distributed API proxy system that transforms LMArena into an OpenAI-compatible API service. Designed for cPanel shared hosting environments with advanced features like rate limiting, token counting, user dashboards, and multi-tenant support.

## 🌟 Features

### Core API Features
- **OpenAI-Compatible Endpoints**: Full compatibility with `/v1/chat/completions`, `/v1/models`, and `/v1/usage`
- **Streaming & Non-Streaming**: Support for both real-time streaming and batch responses
- **Multi-Model Support**: Access to all LMArena models with intelligent routing
- **Rate Limiting**: Sophisticated multi-tier rate limiting with sliding windows
- **Token Counting**: Accurate token tracking for billing and usage analytics

### Enterprise Features
- **Multi-Tenant Architecture**: Support for multiple users with subscription tiers
- **Load Balancing**: Intelligent distribution across multiple browser clients
- **Usage Analytics**: Comprehensive tracking and reporting
- **User Dashboard**: Web-based interface for monitoring and management
- **API Key Management**: Secure authentication and authorization

### Distributed Architecture
- **Browser Client Pool**: Multiple distributed browser automation clients
- **Database-Centric Communication**: Reliable polling-based architecture for cPanel compatibility
- **Automatic Failover**: Robust error handling and client recovery
- **Geographic Distribution**: Support for clients in different locations

## 📁 Project Structure

```
distributed_lmarena_bridge/
├── php_api/                          # Main PHP API application
│   ├── config/
│   │   ├── database.php              # Database configuration and schema
│   │   └── config.php                # Main configuration file
│   ├── includes/
│   │   ├── ApiAuth.php               # Authentication and authorization
│   │   ├── RequestQueue.php          # Request queuing and management
│   │   ├── BrowserSessionManager.php # Browser client management
│   │   └── TokenCounter.php          # Token counting utilities
│   ├── public_html/
│   │   ├── v1/
│   │   │   ├── chat/
│   │   │   │   └── completions.php   # Chat completions endpoint
│   │   │   ├── models.php            # Models listing endpoint
│   │   │   └── usage.php             # Usage statistics endpoint
│   │   ├── api/browser/
│   │   │   ├── register.php          # Browser client registration
│   │   │   ├── poll.php              # Request polling endpoint
│   │   │   └── response.php          # Response submission endpoint
│   │   └── dashboard/
│   │       └── index.php             # User dashboard interface
├── distributed_clients/
│   ├── browser_client.py             # Enhanced Python browser client
│   └── enhanced_tampermonkey_script.js # Advanced Tampermonkey script
├── setup/
│   └── install.php                   # Installation and setup script
└── docs/                             # Documentation
```

## 🚀 Quick Start

### 1. Installation

#### Option A: Web Installation
1. Upload the `php_api` folder to your cPanel public_html directory
2. Navigate to `your-domain.com/setup/install.php`
3. Follow the web-based installation wizard

#### Option B: CLI Installation
```bash
cd setup
php install.php
```

### 2. Database Setup
The installer will create the following tables:
- `users` - User accounts and API keys
- `api_requests` - Request queue and tracking
- `browser_sessions` - Browser client management
- `request_responses` - Streaming response chunks
- `rate_limits` - Rate limiting data
- `usage_analytics` - Usage tracking and billing
- `system_logs` - System monitoring and debugging

### 3. Browser Client Setup

#### Install Dependencies
```bash
cd distributed_clients
pip install aiohttp asyncio
```

#### Start Browser Clients
```bash
python browser_client.py --api-url https://your-domain.com --max-concurrent 5
```

#### Install Tampermonkey Script
1. Install Tampermonkey browser extension
2. Copy the content from `enhanced_tampermonkey_script.js`
3. Create a new script in Tampermonkey
4. Navigate to LMArena.ai to activate the script

### 4. Configuration

#### Environment Variables
Create a `.env` file in the root directory:
```env
DB_HOST=localhost
DB_NAME=lmarena_bridge
DB_USER=your_db_user
DB_PASS=your_db_password
API_BASE_URL=https://your-domain.com
ENVIRONMENT=production
DEBUG=false
```

#### Subscription Tiers
Configure rate limits in `php_api/config/config.php`:
- **Free**: 10/min, 100/hour, 1000/day
- **Premium**: 60/min, 1000/hour, 10000/day
- **Enterprise**: 300/min, 5000/hour, 50000/day

## 🔧 API Usage

### Authentication
All API requests require a Bearer token:
```bash
curl -H "Authorization: Bearer your_api_key" \
     https://your-domain.com/v1/models
```

### Chat Completions
```bash
curl -X POST https://your-domain.com/v1/chat/completions \
  -H "Authorization: Bearer your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": true
  }'
```

### Usage Statistics
```bash
curl -H "Authorization: Bearer your_api_key" \
     https://your-domain.com/v1/usage?range=day&details=true
```

## 🎛️ Dashboard Features

Access the user dashboard at `your-domain.com/dashboard/`:

- **Real-time Usage Monitoring**: Track requests, tokens, and costs
- **Rate Limit Status**: Monitor current usage against limits
- **Request History**: View recent API calls and their status
- **API Key Management**: Secure key generation and rotation
- **Analytics Graphs**: Visual representation of usage patterns

## 🔄 Architecture Overview

### Request Flow
1. **API Request**: Client sends OpenAI-compatible request
2. **Authentication**: API key validation and rate limit check
3. **Queue Management**: Request queued with priority based on subscription
4. **Load Balancing**: Best available browser client selected
5. **Browser Processing**: Tampermonkey script executes request on LMArena
6. **Response Streaming**: Real-time response chunks sent back to client
7. **Analytics**: Usage data recorded for billing and monitoring

### Browser Client Architecture
- **Polling-Based**: Clients poll for new requests (cPanel-compatible)
- **Adaptive Intervals**: Polling frequency adjusts based on load
- **Performance Tracking**: Response times and success rates monitored
- **Automatic Recovery**: Failed clients automatically reconnected

### Database-Centric Design
- **Request Queue**: Persistent storage for reliable processing
- **Response Chunks**: Streaming data stored for reconstruction
- **Session Management**: Browser client state and health tracking
- **Analytics Storage**: Comprehensive usage and performance data

## 🛡️ Security Features

### API Security
- **Secure API Keys**: SHA-256 hashed storage
- **Rate Limiting**: Multi-window sliding rate limits
- **Request Validation**: Input sanitization and validation
- **CORS Protection**: Configurable cross-origin policies

### Data Protection
- **Encrypted Storage**: Sensitive request data encrypted
- **Privacy by Design**: Minimal data collection and retention
- **Audit Logging**: Comprehensive system activity logs
- **Secure Sessions**: Browser client authentication

## 📊 Monitoring & Analytics

### System Monitoring
- **Real-time Metrics**: Active sessions, queue length, response times
- **Health Checks**: Browser client status and performance
- **Error Tracking**: Comprehensive error logging and alerting
- **Capacity Planning**: Usage trends and scaling recommendations

### User Analytics
- **Token Usage**: Detailed breakdown by model and time period
- **Cost Tracking**: Accurate billing calculations
- **Performance Metrics**: Response times and success rates
- **Usage Patterns**: Peak usage identification and optimization

## 🔧 Advanced Configuration

### Load Balancing
Configure in `php_api/config/config.php`:
```php
'load_balancing' => [
    'algorithm' => 'weighted_round_robin',
    'health_check_interval' => 60,
    'failure_threshold' => 3,
    'recovery_time' => 300
]
```

### Model Pricing
Customize pricing per model:
```php
'pricing' => [
    'gpt-4' => ['input' => 0.03, 'output' => 0.06],
    'claude-3-opus' => ['input' => 0.015, 'output' => 0.075]
]
```

### Cache Configuration
Optimize performance with caching:
```php
'cache' => [
    'enabled' => true,
    'type' => 'file',
    'ttl' => [
        'user_data' => 300,
        'model_list' => 3600
    ]
]
```

## 🚀 Deployment

### cPanel Deployment
1. Upload `php_api` to `public_html`
2. Create MySQL database through cPanel
3. Run installation script
4. Configure cron jobs for maintenance

### Maintenance Tasks
Set up cron jobs for:
```bash
# Cleanup old data (hourly)
0 * * * * php /path/to/cleanup.php

# Update browser session status (every 5 minutes)
*/5 * * * * php /path/to/session_cleanup.php

# Generate usage reports (daily)
0 0 * * * php /path/to/generate_reports.php
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

- **Documentation**: Check the `/docs` directory for detailed guides
- **Issues**: Report bugs and feature requests on GitHub
- **Community**: Join our Discord server for support and discussions

## 🔮 Roadmap

- [ ] Auto-scaling browser clients
- [ ] Geographic request routing
- [ ] Advanced analytics dashboard
- [ ] Webhook integrations
- [ ] Multi-language support
- [ ] Docker containerization
- [ ] Kubernetes deployment

---

**Built with ❤️ for the AI community**
