#!/usr/bin/env python3
"""
Deployment and Testing Script for LMArena Bridge Distributed API

This script helps deploy, configure, and test the distributed API system.
"""

import os
import sys
import json
import time
import requests
import subprocess
import argparse
from pathlib import Path
from typing import Dict, Any, Optional

class LMArenaDeployer:
    def __init__(self, config_file: str = "deploy_config.json"):
        self.config_file = config_file
        self.config = self.load_config()
        self.base_dir = Path(__file__).parent
        
    def load_config(self) -> Dict[str, Any]:
        """Load deployment configuration"""
        default_config = {
            "api_base_url": "http://localhost",
            "database": {
                "host": "localhost",
                "name": "lmarena_bridge",
                "user": "root",
                "password": ""
            },
            "admin_email": "admin@example.com",
            "browser_clients": {
                "count": 2,
                "max_concurrent": 5
            },
            "test_api_key": None
        }
        
        if os.path.exists(self.config_file):
            with open(self.config_file, 'r') as f:
                user_config = json.load(f)
                default_config.update(user_config)
        
        return default_config
    
    def save_config(self):
        """Save current configuration"""
        with open(self.config_file, 'w') as f:
            json.dump(self.config, f, indent=2)
    
    def setup_environment(self):
        """Set up the deployment environment"""
        print("🔧 Setting up deployment environment...")
        
        # Create necessary directories
        directories = [
            "php_api/logs",
            "php_api/cache",
            "php_api/uploads",
            "distributed_clients/logs"
        ]
        
        for directory in directories:
            dir_path = self.base_dir / directory
            dir_path.mkdir(parents=True, exist_ok=True)
            print(f"✅ Created directory: {directory}")
        
        # Create .env file
        env_content = f"""
DB_HOST={self.config['database']['host']}
DB_NAME={self.config['database']['name']}
DB_USER={self.config['database']['user']}
DB_PASS={self.config['database']['password']}
API_BASE_URL={self.config['api_base_url']}
ENVIRONMENT=production
DEBUG=false
"""
        
        with open(self.base_dir / ".env", "w") as f:
            f.write(env_content.strip())
        
        print("✅ Environment configuration created")
    
    def install_dependencies(self):
        """Install Python dependencies for browser clients"""
        print("📦 Installing Python dependencies...")
        
        requirements_file = self.base_dir / "distributed_clients" / "requirements.txt"
        
        try:
            subprocess.run([
                sys.executable, "-m", "pip", "install", "-r", str(requirements_file)
            ], check=True, capture_output=True)
            print("✅ Python dependencies installed successfully")
        except subprocess.CalledProcessError as e:
            print(f"❌ Failed to install dependencies: {e}")
            return False
        
        return True
    
    def run_installation(self):
        """Run the PHP installation script"""
        print("🚀 Running PHP installation...")
        
        # Prepare installation data
        install_data = {
            "db_host": self.config['database']['host'],
            "db_name": self.config['database']['name'],
            "db_user": self.config['database']['user'],
            "db_pass": self.config['database']['password'],
            "admin_email": self.config['admin_email'],
            "api_base_url": self.config['api_base_url']
        }
        
        # Run installation via HTTP if possible, otherwise CLI
        install_url = f"{self.config['api_base_url']}/setup/install.php"
        
        try:
            response = requests.post(install_url, data=install_data, timeout=30)
            if response.status_code == 200:
                print("✅ PHP installation completed via web interface")
                # Try to extract API key from response
                if "lma_" in response.text:
                    import re
                    api_key_match = re.search(r'lma_[a-f0-9]{64}', response.text)
                    if api_key_match:
                        self.config['test_api_key'] = api_key_match.group()
                        self.save_config()
                        print(f"🔑 Admin API key saved: {self.config['test_api_key'][:20]}...")
                return True
            else:
                print(f"⚠️ Web installation failed: {response.status_code}")
        except requests.RequestException:
            print("⚠️ Web installation not available, trying CLI...")
        
        # Fallback to CLI installation
        try:
            install_script = self.base_dir / "setup" / "install.php"
            result = subprocess.run([
                "php", str(install_script)
            ], input=f"{install_data['db_host']}\n{install_data['db_name']}\n{install_data['db_user']}\n{install_data['db_pass']}\n{install_data['admin_email']}\n{install_data['api_base_url']}\n",
            text=True, capture_output=True)
            
            if result.returncode == 0:
                print("✅ CLI installation completed")
                # Try to extract API key from output
                if "lma_" in result.stdout:
                    import re
                    api_key_match = re.search(r'lma_[a-f0-9]{64}', result.stdout)
                    if api_key_match:
                        self.config['test_api_key'] = api_key_match.group()
                        self.save_config()
                        print(f"🔑 Admin API key saved: {self.config['test_api_key'][:20]}...")
                return True
            else:
                print(f"❌ CLI installation failed: {result.stderr}")
                return False
        except Exception as e:
            print(f"❌ Installation failed: {e}")
            return False
    
    def start_browser_clients(self):
        """Start browser client processes"""
        print("🌐 Starting browser clients...")
        
        client_count = self.config['browser_clients']['count']
        max_concurrent = self.config['browser_clients']['max_concurrent']
        
        processes = []
        
        for i in range(client_count):
            client_id = f"client_{i+1}"
            
            try:
                process = subprocess.Popen([
                    sys.executable,
                    str(self.base_dir / "distributed_clients" / "browser_client.py"),
                    "--api-url", self.config['api_base_url'],
                    "--client-id", client_id,
                    "--max-concurrent", str(max_concurrent)
                ], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                
                processes.append((client_id, process))
                print(f"✅ Started browser client: {client_id}")
                
            except Exception as e:
                print(f"❌ Failed to start client {client_id}: {e}")
        
        if processes:
            print(f"🎉 Started {len(processes)} browser clients")
            print("💡 Tip: Install the Tampermonkey script and navigate to LMArena.ai")
            
            # Wait a bit for clients to register
            time.sleep(5)
            
            return processes
        else:
            print("❌ No browser clients started")
            return []
    
    def test_api_endpoints(self):
        """Test the API endpoints"""
        print("🧪 Testing API endpoints...")
        
        if not self.config.get('test_api_key'):
            print("❌ No API key available for testing")
            return False
        
        headers = {
            "Authorization": f"Bearer {self.config['test_api_key']}",
            "Content-Type": "application/json"
        }
        
        base_url = self.config['api_base_url']
        
        # Test models endpoint
        try:
            response = requests.get(f"{base_url}/v1/models", headers=headers, timeout=10)
            if response.status_code == 200:
                models = response.json()
                print(f"✅ Models endpoint: {len(models.get('data', []))} models available")
            else:
                print(f"❌ Models endpoint failed: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Models endpoint error: {e}")
            return False
        
        # Test usage endpoint
        try:
            response = requests.get(f"{base_url}/v1/usage", headers=headers, timeout=10)
            if response.status_code == 200:
                print("✅ Usage endpoint working")
            else:
                print(f"❌ Usage endpoint failed: {response.status_code}")
        except Exception as e:
            print(f"❌ Usage endpoint error: {e}")
        
        # Test chat completions (simple test)
        try:
            test_request = {
                "model": "gpt-3.5-turbo",
                "messages": [{"role": "user", "content": "Hello, this is a test."}],
                "stream": False,
                "max_tokens": 10
            }
            
            response = requests.post(
                f"{base_url}/v1/chat/completions",
                headers=headers,
                json=test_request,
                timeout=30
            )
            
            if response.status_code == 200:
                print("✅ Chat completions endpoint working")
            elif response.status_code == 503:
                print("⚠️ Chat completions endpoint available but no browser clients connected")
            else:
                print(f"❌ Chat completions failed: {response.status_code}")
                
        except Exception as e:
            print(f"❌ Chat completions error: {e}")
        
        return True
    
    def show_dashboard_info(self):
        """Show dashboard access information"""
        print("\n📊 Dashboard Information:")
        print(f"Dashboard URL: {self.config['api_base_url']}/dashboard/")
        if self.config.get('test_api_key'):
            print(f"Admin API Key: {self.config['test_api_key']}")
        print("\n🔗 API Endpoints:")
        print(f"Models: {self.config['api_base_url']}/v1/models")
        print(f"Chat: {self.config['api_base_url']}/v1/chat/completions")
        print(f"Usage: {self.config['api_base_url']}/v1/usage")
    
    def deploy(self):
        """Run full deployment process"""
        print("🚀 Starting LMArena Bridge Distributed API Deployment\n")
        
        # Setup environment
        self.setup_environment()
        
        # Install dependencies
        if not self.install_dependencies():
            return False
        
        # Run installation
        if not self.run_installation():
            return False
        
        # Start browser clients
        processes = self.start_browser_clients()
        
        # Test API
        self.test_api_endpoints()
        
        # Show info
        self.show_dashboard_info()
        
        print("\n🎉 Deployment completed successfully!")
        print("\n📝 Next steps:")
        print("1. Install Tampermonkey script in your browser")
        print("2. Navigate to LMArena.ai to activate browser automation")
        print("3. Test the API with your favorite OpenAI-compatible client")
        print("4. Access the dashboard to monitor usage")
        
        return True

def main():
    parser = argparse.ArgumentParser(description='Deploy LMArena Bridge Distributed API')
    parser.add_argument('--config', default='deploy_config.json', help='Configuration file')
    parser.add_argument('--api-url', help='API base URL')
    parser.add_argument('--db-host', help='Database host')
    parser.add_argument('--db-name', help='Database name')
    parser.add_argument('--db-user', help='Database user')
    parser.add_argument('--db-pass', help='Database password')
    parser.add_argument('--admin-email', help='Admin email')
    parser.add_argument('--test-only', action='store_true', help='Only run tests')
    
    args = parser.parse_args()
    
    deployer = LMArenaDeployer(args.config)
    
    # Override config with command line arguments
    if args.api_url:
        deployer.config['api_base_url'] = args.api_url
    if args.db_host:
        deployer.config['database']['host'] = args.db_host
    if args.db_name:
        deployer.config['database']['name'] = args.db_name
    if args.db_user:
        deployer.config['database']['user'] = args.db_user
    if args.db_pass:
        deployer.config['database']['password'] = args.db_pass
    if args.admin_email:
        deployer.config['admin_email'] = args.admin_email
    
    deployer.save_config()
    
    if args.test_only:
        deployer.test_api_endpoints()
        deployer.show_dashboard_info()
    else:
        deployer.deploy()

if __name__ == "__main__":
    main()
