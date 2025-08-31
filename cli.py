#!/usr/bin/env python3
"""
LMArena Bridge CLI 入口脚本
"""

import os
# 设置环境变量以支持UTF-8
os.environ['PYTHONIOENCODING'] = 'utf-8'

from modules.cli import cli

if __name__ == "__main__":
    cli()
