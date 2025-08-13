#!/usr/bin/env python3
"""
Distributed Browser Client for LMArena Bridge

This client polls the PHP API for requests and processes them through
the browser automation system using the enhanced Tampermonkey script.
"""

import asyncio
import aiohttp
import json
import time
import logging
import platform
import uuid
import signal
import sys
from datetime import datetime
from typing import Optional, Dict, Any
import argparse

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

class BrowserClient:
    def __init__(self, api_base_url: str, client_id: str = None, max_concurrent: int = 5):
        self.api_base_url = api_base_url.rstrip('/')
        self.client_id = client_id or f"client_{platform.node()}_{uuid.uuid4().hex[:8]}"
        self.session_id: Optional[str] = None
        self.max_concurrent = max_concurrent
        self.current_load = 0
        self.running = False
        self.session: Optional[aiohttp.ClientSession] = None
        
        # Performance tracking
        self.total_requests = 0
        self.successful_requests = 0
        self.total_response_time = 0
        self.last_heartbeat = time.time()
        
        # Adaptive polling
        self.base_poll_interval = 5
        self.current_poll_interval = 5
        self.last_poll_time = 0
        
    async def start(self):
        """Start the browser client"""
        logger.info(f"Starting browser client: {self.client_id}")
        
        # Create HTTP session
        timeout = aiohttp.ClientTimeout(total=30)
        self.session = aiohttp.ClientSession(timeout=timeout)
        
        # Register with the API
        await self.register()
        
        # Start main polling loop
        self.running = True
        await self.polling_loop()
        
    async def stop(self):
        """Stop the browser client"""
        logger.info("Stopping browser client...")
        self.running = False
        
        if self.session:
            await self.session.close()
            
    async def register(self):
        """Register this client with the API"""
        registration_data = {
            'client_identifier': self.client_id,
            'capabilities': {
                'supported_models': ['text', 'image'],
                'max_concurrent_requests': self.max_concurrent,
                'streaming_support': True,
                'platform': platform.system(),
                'python_version': platform.python_version()
            },
            'max_concurrent_requests': self.max_concurrent,
            'geographic_location': self.detect_location()
        }
        
        try:
            async with self.session.post(
                f"{self.api_base_url}/api/browser/register.php",
                json=registration_data
            ) as response:
                if response.status == 200:
                    data = await response.json()
                    self.session_id = data['session_id']
                    logger.info(f"Registered successfully with session ID: {self.session_id}")
                else:
                    error_text = await response.text()
                    raise Exception(f"Registration failed: {response.status} - {error_text}")
                    
        except Exception as e:
            logger.error(f"Failed to register: {e}")
            raise
            
    async def polling_loop(self):
        """Main polling loop"""
        logger.info("Starting polling loop...")
        
        while self.running:
            try:
                # Calculate adaptive poll interval
                self.update_poll_interval()
                
                # Poll for new requests
                await self.poll_for_requests()
                
                # Wait before next poll
                await asyncio.sleep(self.current_poll_interval)
                
            except Exception as e:
                logger.error(f"Error in polling loop: {e}")
                await asyncio.sleep(5)  # Wait before retrying
                
    async def poll_for_requests(self):
        """Poll the API for new requests"""
        if not self.session_id:
            logger.warning("No session ID, skipping poll")
            return
            
        # Prepare polling data with current metrics
        poll_data = {
            'current_load': self.current_load,
            'average_response_time': self.get_average_response_time(),
            'success_rate': self.get_success_rate(),
            'last_poll': time.time()
        }
        
        headers = {
            'X-Browser-Session-ID': self.session_id,
            'Content-Type': 'application/json'
        }
        
        try:
            async with self.session.post(
                f"{self.api_base_url}/api/browser/poll.php",
                json=poll_data,
                headers=headers
            ) as response:
                if response.status == 200:
                    data = await response.json()
                    
                    if data.get('has_request'):
                        # Process the request
                        request_info = data['request']
                        await self.process_request(request_info)
                    else:
                        # Update poll interval based on server recommendation
                        recommended_interval = data.get('poll_interval', self.base_poll_interval)
                        self.current_poll_interval = min(recommended_interval, 30)  # Cap at 30 seconds
                        
                else:
                    logger.warning(f"Poll failed: {response.status}")
                    
        except Exception as e:
            logger.error(f"Polling error: {e}")
            
    async def process_request(self, request_info: Dict[str, Any]):
        """Process a request from the API"""
        request_id = request_info['request_id']
        logger.info(f"Processing request: {request_id}")
        
        start_time = time.time()
        self.current_load += 1
        self.total_requests += 1
        
        try:
            # Here you would integrate with your browser automation
            # For now, we'll simulate the processing
            await self.simulate_browser_processing(request_info)
            
            # Mark as successful
            self.successful_requests += 1
            response_time = (time.time() - start_time) * 1000  # Convert to milliseconds
            self.total_response_time += response_time
            
            # Send completion notification
            await self.send_completion(request_id, "Simulated response", response_time)
            
        except Exception as e:
            logger.error(f"Failed to process request {request_id}: {e}")
            await self.send_error(request_id, str(e))
            
        finally:
            self.current_load -= 1
            
    async def simulate_browser_processing(self, request_info: Dict[str, Any]):
        """Simulate browser processing (replace with actual browser automation)"""
        # This is where you would integrate with the actual browser automation
        # using the enhanced Tampermonkey script
        
        model = request_info['model']
        messages = request_info['messages']
        is_streaming = request_info.get('stream', True)
        
        logger.info(f"Simulating processing for model: {model}")
        
        if is_streaming:
            # Simulate streaming response
            chunks = ["Hello", " there", "! This", " is", " a", " simulated", " response", "."]
            for i, chunk in enumerate(chunks):
                await self.send_chunk(request_info['request_id'], chunk, i)
                await asyncio.sleep(0.1)  # Simulate streaming delay
        else:
            # Simulate non-streaming response
            await asyncio.sleep(1)  # Simulate processing time
            
    async def send_chunk(self, request_id: str, content: str, sequence: int):
        """Send a response chunk to the API"""
        chunk_data = {
            'request_id': request_id,
            'type': 'chunk',
            'content': content,
            'sequence': sequence
        }
        
        await self.send_response(chunk_data)
        
    async def send_completion(self, request_id: str, full_response: str, response_time_ms: float):
        """Send request completion to the API"""
        completion_data = {
            'request_id': request_id,
            'type': 'complete',
            'full_response': full_response,
            'response_time_ms': response_time_ms
        }
        
        await self.send_response(completion_data)
        
    async def send_error(self, request_id: str, error_message: str):
        """Send error notification to the API"""
        error_data = {
            'request_id': request_id,
            'type': 'error',
            'error_message': error_message,
            'error_type': 'processing_error'
        }
        
        await self.send_response(error_data)
        
    async def send_response(self, response_data: Dict[str, Any]):
        """Send response data to the API"""
        if not self.session_id:
            logger.warning("No session ID, cannot send response")
            return
            
        headers = {
            'X-Browser-Session-ID': self.session_id,
            'Content-Type': 'application/json'
        }
        
        try:
            async with self.session.post(
                f"{self.api_base_url}/api/browser/response.php",
                json=response_data,
                headers=headers
            ) as response:
                if response.status != 200:
                    logger.warning(f"Failed to send response: {response.status}")
                    
        except Exception as e:
            logger.error(f"Error sending response: {e}")
            
    def update_poll_interval(self):
        """Update polling interval based on current load and activity"""
        if self.current_load >= self.max_concurrent:
            # At capacity, poll less frequently
            self.current_poll_interval = min(self.current_poll_interval * 1.5, 30)
        elif self.current_load == 0:
            # No load, can poll more frequently
            self.current_poll_interval = max(self.current_poll_interval * 0.8, 1)
        else:
            # Moderate load, use base interval
            self.current_poll_interval = self.base_poll_interval
            
    def get_average_response_time(self) -> float:
        """Get average response time in milliseconds"""
        if self.successful_requests == 0:
            return 0.0
        return self.total_response_time / self.successful_requests
        
    def get_success_rate(self) -> float:
        """Get success rate as percentage"""
        if self.total_requests == 0:
            return 100.0
        return (self.successful_requests / self.total_requests) * 100
        
    def detect_location(self) -> str:
        """Detect geographic location (simplified)"""
        # In production, you might use a GeoIP service
        return f"{platform.system()}_{platform.node()}"
        
    def print_stats(self):
        """Print current statistics"""
        logger.info(f"Stats - Total: {self.total_requests}, "
                   f"Successful: {self.successful_requests}, "
                   f"Success Rate: {self.get_success_rate():.1f}%, "
                   f"Avg Response Time: {self.get_average_response_time():.1f}ms, "
                   f"Current Load: {self.current_load}/{self.max_concurrent}")

async def main():
    parser = argparse.ArgumentParser(description='LMArena Bridge Browser Client')
    parser.add_argument('--api-url', required=True, help='API base URL')
    parser.add_argument('--client-id', help='Client identifier')
    parser.add_argument('--max-concurrent', type=int, default=5, help='Max concurrent requests')
    parser.add_argument('--stats-interval', type=int, default=60, help='Stats print interval in seconds')
    
    args = parser.parse_args()
    
    # Create client
    client = BrowserClient(
        api_base_url=args.api_url,
        client_id=args.client_id,
        max_concurrent=args.max_concurrent
    )
    
    # Setup signal handlers
    def signal_handler(signum, frame):
        logger.info("Received shutdown signal")
        asyncio.create_task(client.stop())
        
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    
    # Start stats printing task
    async def stats_task():
        while client.running:
            await asyncio.sleep(args.stats_interval)
            if client.running:
                client.print_stats()
                
    # Run client
    try:
        await asyncio.gather(
            client.start(),
            stats_task()
        )
    except KeyboardInterrupt:
        logger.info("Shutting down...")
    finally:
        await client.stop()

if __name__ == "__main__":
    asyncio.run(main())
