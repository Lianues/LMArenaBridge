#!/usr/bin/env python3
"""
LMArena Bridge CLI Tool
"""

import json
import os
import sys
from datetime import datetime
from pathlib import Path

import click
from rich.console import Console
from rich.table import Table
from rich.panel import Panel
from rich.prompt import Prompt, Confirm
from rich.tree import Tree
from rich import box

# 设置环境变量以支持UTF-8
os.environ['PYTHONIOENCODING'] = 'utf-8'

console = Console(force_terminal=True, legacy_windows=False)

# 文件路径
BASE_DIR = Path(__file__).parent.parent
MODELS_FILE = BASE_DIR / "models.json"
AVAILABLE_MODELS_FILE = BASE_DIR / "available_models.json"
MODEL_ENDPOINT_MAP_FILE = BASE_DIR / "model_endpoint_map.json"
CONFIG_FILE = BASE_DIR / "config.jsonc"


def load_json_file(file_path: Path):
    """加载JSON文件"""
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            if file_path.suffix == '.jsonc':
                # 处理JSONC注释
                content = f.read()
                lines = content.split('\n')
                filtered_lines = []
                for line in lines:
                    if '//' in line:
                        comment_pos = line.find('//')
                        line = line[:comment_pos]
                    filtered_lines.append(line)
                content = '\n'.join(filtered_lines)
                # 移除控制字符
                content = ''.join(char for char in content if ord(char) >= 32 or char in '\n\r\t')
                return json.loads(content)
            else:
                return json.load(f)
    except FileNotFoundError:
        console.print(f"[red]错误: 文件 {file_path} 不存在[/red]")
        return {}
    except json.JSONDecodeError as e:
        console.print(f"[red]错误: 解析 {file_path} 失败: {e}[/red]")
        return {}


def save_json_file(file_path: Path, data):
    """保存JSON文件"""
    try:
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2 if file_path.suffix == '.jsonc' else 4, ensure_ascii=False)
        return True
    except Exception as e:
        console.print(f"[red]错误: 保存 {file_path} 失败: {e}[/red]")
        return False


@click.group()
@click.version_option(version="1.0.0", prog_name="LMArena Bridge CLI")
def cli():
    """LMArena Bridge CLI Tool - 便捷的模型管理工具"""
    pass


@cli.command('list')
@click.option('--provider', '-p', help='按提供商筛选')
@click.option('--organization', '-o', help='按组织筛选')
@click.option('--capability', '-c', help='按能力筛选')
@click.option('--format', '-f', type=click.Choice(['table', 'json', 'simple']), default='table', help='输出格式')
def list_models(provider, organization, capability, format):
    """列出所有可用模型"""
    
    available_models = load_json_file(AVAILABLE_MODELS_FILE)
    if not available_models:
        console.print("[yellow]没有找到可用模型数据[/yellow]")
        return
    
    # 确保是列表格式
    if not isinstance(available_models, list):
        console.print("[red]模型数据格式错误[/red]")
        return
        
    filtered_models = available_models
    if provider:
        filtered_models = [m for m in filtered_models if isinstance(m, dict) and m.get('provider', '').lower() == provider.lower()]
    if organization:
        filtered_models = [m for m in filtered_models if isinstance(m, dict) and m.get('organization', '').lower() == organization.lower()]
    if capability:
        filtered_models = [m for m in filtered_models 
                          if isinstance(m, dict) and capability.lower() in str(m.get('capabilities', {})).lower()]
    
    if format == 'json':
        console.print_json(data=filtered_models)
        return
    
    if format == 'simple':
        for model in filtered_models:
            if isinstance(model, dict):
                console.print(f"• {model.get('publicName', 'Unknown')} ({model.get('id', 'No ID')})")
        return
    
    # 表格格式
    table = Table(title="可用模型列表", box=box.ROUNDED)
    table.add_column("模型名称", style="cyan", no_wrap=True)
    table.add_column("ID", style="dim", max_width=20)
    table.add_column("组织", style="green")
    table.add_column("提供商", style="blue")
    table.add_column("能力", style="yellow")
    
    for model in filtered_models:
        if isinstance(model, dict):
            name = model.get('publicName', 'Unknown')
            model_id = model.get('id', 'No ID')
            org = model.get('organization', 'Unknown')
            provider_name = model.get('provider', 'Unknown')
            
            # 解析能力
            capabilities = model.get('capabilities', {})
            input_caps = capabilities.get('inputCapabilities', {}) if capabilities else {}
            output_caps = capabilities.get('outputCapabilities', {}) if capabilities else {}
            
            cap_list = []
            if input_caps.get('text'): cap_list.append('Text')
            if input_caps.get('image'): cap_list.append('Image')
            if output_caps.get('image'): cap_list.append('GenImg')
            if output_caps.get('video'): cap_list.append('Video')
            if output_caps.get('search'): cap_list.append('Search')
            
            caps_str = ' '.join(cap_list) if cap_list else 'Unknown'
            
            table.add_row(name, model_id[:20] + "..." if len(model_id) > 20 else model_id, 
                         org, provider_name, caps_str)
    
    console.print(table)
    console.print(f"\n[dim]共找到 {len(filtered_models)} 个模型[/dim]")


@cli.command()
@click.argument('model_name')
def show(model_name: str):
    """显示特定模型的详细信息"""
    
    available_models = load_json_file(AVAILABLE_MODELS_FILE)
    if not available_models or not isinstance(available_models, list):
        console.print("[yellow]没有找到可用模型数据[/yellow]")
        return
    
    # 查找模型
    model = None
    for m in available_models:
        if isinstance(m, dict) and (m.get('publicName', '').lower() == model_name.lower() or 
            m.get('id', '').lower() == model_name.lower()):
            model = m
            break
    
    if not model:
        console.print(f"[red]未找到模型: {model_name}[/red]")
        return
    
    # 显示模型详情
    panel_content = []
    panel_content.append(f"[bold cyan]模型名称:[/bold cyan] {model.get('publicName', 'Unknown')}")
    panel_content.append(f"[bold green]模型ID:[/bold green] {model.get('id', 'No ID')}")
    panel_content.append(f"[bold blue]组织:[/bold blue] {model.get('organization', 'Unknown')}")
    panel_content.append(f"[bold magenta]提供商:[/bold magenta] {model.get('provider', 'Unknown')}")
    
    # 能力详情
    capabilities = model.get('capabilities', {})
    if capabilities:
        panel_content.append("\n[bold yellow]能力详情:[/bold yellow]")
        
        input_caps = capabilities.get('inputCapabilities', {})
        if input_caps:
            panel_content.append("  [dim]输入能力:[/dim]")
            for cap, value in input_caps.items():
                if isinstance(value, bool) and value:
                    panel_content.append(f"    [OK] {cap}")
        
        output_caps = capabilities.get('outputCapabilities', {})
        if output_caps:
            panel_content.append("  [dim]输出能力:[/dim]")
            for cap, value in output_caps.items():
                if isinstance(value, bool) and value:
                    panel_content.append(f"    [OK] {cap}")
    
    panel = Panel(
        "\n".join(panel_content),
        title=f"{model.get('publicName', 'Unknown')} 详情",
        border_style="cyan"
    )
    console.print(panel)


@cli.command()
def config():
    """显示当前配置信息"""
    
    config_data = load_json_file(CONFIG_FILE)
    models_data = load_json_file(MODELS_FILE)
    
    if not config_data:
        console.print("[yellow]没有找到配置文件[/yellow]")
        return
    
    # 创建配置树
    tree = Tree("[bold cyan]LMArena Bridge 配置[/bold cyan]")
    
    # 基本信息
    if isinstance(config_data, dict):
        basic_branch = tree.add("[bold green]基本信息[/bold green]")
        basic_branch.add(f"版本: {config_data.get('version', 'Unknown')}")
        basic_branch.add(f"会话ID: {str(config_data.get('session_id', 'Not set'))[:20]}...")
        
        # 功能开关
        features_branch = tree.add("[bold blue]功能开关[/bold blue]")
        features_branch.add(f"绕过敏感词: {'[OK]' if config_data.get('bypass_enabled') else '[X]'}")
        features_branch.add(f"酒馆模式: {'[OK]' if config_data.get('tavern_mode_enabled') else '[X]'}")
    
    # 模型映射
    models_branch = tree.add("[bold yellow]模型映射[/bold yellow]")
    if isinstance(models_data, dict) and models_data:
        for model_name, model_id in models_data.items():
            models_branch.add(f"{model_name} -> {str(model_id)[:20]}...")
    else:
        models_branch.add("[dim]无模型映射[/dim]")
    
    console.print(tree)


@cli.command()
@click.argument('model_name')
@click.argument('model_id')
@click.option('--force', '-f', is_flag=True, help='强制覆盖已存在的映射')
def add(model_name: str, model_id: str, force: bool):
    """添加模型映射"""

    models_data = load_json_file(MODELS_FILE)
    if not isinstance(models_data, dict):
        models_data = {}

    # 检查是否已存在
    if model_name in models_data and not force:
        console.print(f"[yellow]模型 {model_name} 已存在，使用 --force 强制覆盖[/yellow]")
        return

    # 添加映射
    models_data[model_name] = model_id

    if save_json_file(MODELS_FILE, models_data):
        console.print(f"[green]成功添加模型映射: {model_name} -> {model_id}[/green]")
    else:
        console.print("[red]添加模型映射失败[/red]")


@cli.command()
@click.argument('model_name')
@click.option('--confirm', '-y', is_flag=True, help='跳过确认提示')
def remove(model_name: str, confirm: bool):
    """删除模型映射"""

    models_data = load_json_file(MODELS_FILE)
    if not isinstance(models_data, dict):
        console.print("[yellow]没有找到模型映射[/yellow]")
        return

    if model_name not in models_data:
        console.print(f"[yellow]模型 {model_name} 不存在[/yellow]")
        return

    if not confirm:
        if not Confirm.ask(f"确定要删除模型映射 [red]{model_name}[/red] 吗？"):
            console.print("[yellow]操作已取消[/yellow]")
            return

    del models_data[model_name]

    if save_json_file(MODELS_FILE, models_data):
        console.print(f"[green]成功删除模型映射: {model_name}[/green]")
    else:
        console.print("[red]删除模型映射失败[/red]")


@cli.command()
def mappings():
    """显示当前模型映射"""

    models_data = load_json_file(MODELS_FILE)

    if not isinstance(models_data, dict) or not models_data:
        console.print("[yellow]没有找到模型映射[/yellow]")
        return

    table = Table(title="模型映射表", box=box.ROUNDED)
    table.add_column("模型名称", style="cyan", no_wrap=True)
    table.add_column("模型ID", style="green")

    for model_name, model_id in models_data.items():
        table.add_row(model_name, str(model_id))

    console.print(table)
    console.print(f"\n[dim]共有 {len(models_data)} 个模型映射[/dim]")


@cli.group()
def backup():
    """备份和恢复"""
    pass


@backup.command()
@click.option('--output', '-o', default='backup.json', help='备份文件路径')
def create(output: str):
    """创建配置备份"""

    backup_data = {
        'timestamp': datetime.now().isoformat(),
        'version': '1.0.0',
        'config': load_json_file(CONFIG_FILE),
        'models': load_json_file(MODELS_FILE),
        'available_models': load_json_file(AVAILABLE_MODELS_FILE),
        'endpoint_map': load_json_file(MODEL_ENDPOINT_MAP_FILE)
    }

    try:
        with open(output, 'w', encoding='utf-8') as f:
            json.dump(backup_data, f, indent=2, ensure_ascii=False)
        console.print(f"[green]备份已创建: {output}[/green]")
    except Exception as e:
        console.print(f"[red]创建备份失败: {e}[/red]")


@backup.command()
@click.argument('backup_file')
@click.option('--confirm', '-y', is_flag=True, help='跳过确认提示')
def restore(backup_file: str, confirm: bool):
    """恢复配置备份"""

    if not confirm:
        if not Confirm.ask(f"确定要从 [yellow]{backup_file}[/yellow] 恢复配置吗？这将覆盖当前配置。"):
            console.print("[yellow]操作已取消[/yellow]")
            return

    try:
        with open(backup_file, 'r', encoding='utf-8') as f:
            backup_data = json.load(f)

        # 恢复各个文件
        success_count = 0
        total_count = 0

        for key, file_path in [
            ('config', CONFIG_FILE),
            ('models', MODELS_FILE),
            ('available_models', AVAILABLE_MODELS_FILE),
            ('endpoint_map', MODEL_ENDPOINT_MAP_FILE)
        ]:
            if key in backup_data and backup_data[key]:
                if save_json_file(file_path, backup_data[key]):
                    success_count += 1
                total_count += 1

        console.print(f"[green]恢复完成: {success_count}/{total_count} 个文件成功恢复[/green]")

    except Exception as e:
        console.print(f"[red]恢复备份失败: {e}[/red]")


@cli.command()
def validate():
    """验证配置文件"""
    console.print("[bold cyan]开始验证配置文件...[/bold cyan]")

    issues = []

    # 验证各个文件
    files_to_check = [
        (CONFIG_FILE, "config.jsonc"),
        (MODELS_FILE, "models.json"),
        (AVAILABLE_MODELS_FILE, "available_models.json"),
        (MODEL_ENDPOINT_MAP_FILE, "model_endpoint_map.json")
    ]

    for file_path, file_name in files_to_check:
        data = load_json_file(file_path)
        if not data:
            issues.append(f"[X] {file_name} 文件无法加载或解析")

    # 显示结果
    if issues:
        console.print(f"\n[red]发现 {len(issues)} 个问题:[/red]")
        for issue in issues:
            console.print(f"  {issue}")
    else:
        console.print("\n[green]所有配置文件验证通过！[/green]")


@cli.command()
def version():
    """显示版本信息"""

    config_data = load_json_file(CONFIG_FILE)

    # 创建版本信息面板
    version_content = []
    version_content.append("[bold cyan]LMArena Bridge CLI[/bold cyan]")
    version_content.append("CLI版本: [green]1.0.0[/green]")

    if isinstance(config_data, dict):
        bridge_version = config_data.get('version', 'Unknown')
        version_content.append(f"Bridge版本: [green]{bridge_version}[/green]")

    version_content.append(f"Python版本: [yellow]{sys.version.split()[0]}[/yellow]")

    panel = Panel(
        "\n".join(version_content),
        title="版本信息",
        border_style="green"
    )
    console.print(panel)


if __name__ == "__main__":
    cli()
