"""
Structured Logging Utility for Deployer
Logs to console (colorized) and file (plain text)
"""

import os
import sys
import logging
from datetime import datetime
from pathlib import Path


class ColoredFormatter(logging.Formatter):
    """Custom formatter with color support for console"""
    
    COLORS = {
        'DEBUG': '\033[36m',      # Cyan
        'INFO': '\033[32m',       # Green
        'WARNING': '\033[33m',    # Yellow
        'ERROR': '\033[31m',      # Red
        'CRITICAL': '\033[35m',   # Magenta
    }
    RESET = '\033[0m'
    
    def format(self, record):
        levelname = record.levelname
        if levelname in self.COLORS:
            record.levelname = f"{self.COLORS[levelname]}{levelname}{self.RESET}"
        return super().format(record)


def setup_logger(name: str, log_dir: str = "deployer/logs", log_level: str = "INFO") -> logging.Logger:
    """
    Setup dual logging: console (colorized) + file (plain)
    
    Args:
        name: Logger name (e.g., 'deployer.ftp', 'deployer.db')
        log_dir: Directory to store log files
        log_level: DEBUG, INFO, WARNING, ERROR
    
    Returns:
        Configured logger instance
    """
    
    # Ensure log directory exists
    log_path = Path(log_dir)
    log_path.mkdir(parents=True, exist_ok=True)
    
    # Create logger
    logger = logging.getLogger(name)
    logger.setLevel(getattr(logging, log_level.upper(), logging.INFO))
    
    # Clear existing handlers to avoid duplicates
    logger.handlers.clear()
    
    # Console handler (colorized)
    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setLevel(getattr(logging, log_level.upper(), logging.INFO))
    console_formatter = ColoredFormatter(
        fmt='[%(asctime)s] %(levelname)-8s | %(name)s | %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    console_handler.setFormatter(console_formatter)
    logger.addHandler(console_handler)
    
    # File handler (plain text)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    log_file = log_path / f"deployer_{timestamp}.log"
    file_handler = logging.FileHandler(log_file, mode='a', encoding='utf-8')
    file_handler.setLevel(logging.DEBUG)  # Log everything to file
    file_formatter = logging.Formatter(
        fmt='[%(asctime)s] %(levelname)-8s | %(name)s | %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    file_handler.setFormatter(file_formatter)
    logger.addHandler(file_handler)
    
    return logger, str(log_file)


def get_logger(name: str) -> logging.Logger:
    """Get existing logger by name"""
    return logging.getLogger(name)


def cleanup_old_logs(log_dir: str = "deployer/logs", keep_count: int = 10):
    """Remove old log files, keeping only the N most recent"""
    log_path = Path(log_dir)
    if not log_path.exists():
        return
    
    log_files = sorted(log_path.glob("deployer_*.log"), key=os.path.getmtime, reverse=True)
    
    # Delete files beyond retention count
    for old_log in log_files[keep_count:]:
        try:
            old_log.unlink()
            get_logger(__name__).debug(f"Cleaned up old log: {old_log.name}")
        except Exception as e:
            get_logger(__name__).warning(f"Failed to clean up {old_log.name}: {e}")
