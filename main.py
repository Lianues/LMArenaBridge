import sys
from modules.cli import cli


def main():
    """主入口函数"""
    if len(sys.argv) > 1:
        # 如果有命令行参数，启动CLI
        cli()
    else:
        # 默认显示帮助信息
        print("LMArena Bridge CLI Tool")
        print("使用 'python main.py --help' 查看可用命令")
        print("或者使用 'python main.py list' 列出所有模型")


if __name__ == "__main__":
    main()
