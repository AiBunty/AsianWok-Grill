"""
FTP Client - Handle FTP connections, uploads, and error handling
"""

import ftplib
import socket
from pathlib import Path
from typing import Optional, Dict, Tuple
from dataclasses import dataclass
import time
from utils.logger import get_logger

logger = get_logger(__name__)


@dataclass
class FTPUploadResult:
    """Result of FTP upload operation"""
    file_path: str
    remote_path: str
    success: bool
    size_bytes: int
    time_seconds: float
    error: Optional[str] = None


class FTPClient:
    """FTP client with retry logic and connection pooling"""
    
    def __init__(self, host: str, user: str, password: str, 
                 port: int = 21, timeout: int = 60, 
                 passive_mode: bool = True, retries: int = 3, 
                 retry_backoff: float = 2.0):
        """
        Initialize FTP client
        
        Args:
            host: FTP hostname
            user: FTP username
            password: FTP password
            port: FTP port (default 21)
            timeout: Connection timeout in seconds
            passive_mode: Use passive mode
            retries: Number of retry attempts
            retry_backoff: Exponential backoff multiplier
        """
        self.host = host
        self.user = user
        self.password = password
        self.port = port
        self.timeout = timeout
        self.passive_mode = passive_mode
        self.retries = retries
        self.retry_backoff = retry_backoff
        
        self.ftp: Optional[ftplib.FTP] = None
        self.is_connected = False
    
    def connect(self) -> bool:
        """
        Connect to FTP server with retry logic
        
        Returns:
            True if connected, False if failed after retries
        """
        for attempt in range(1, self.retries + 1):
            try:
                logger.info(f"FTP connecting to {self.host}:{self.port} (attempt {attempt}/{self.retries})...")
                
                self.ftp = ftplib.FTP()
                self.ftp.set_debuglevel(0)
                self.ftp.connect(self.host, self.port, timeout=self.timeout)
                self.ftp.login(self.user, self.password)
                
                if self.passive_mode:
                    self.ftp.set_pasv(True)
                
                logger.info(f"✓ FTP connected successfully | Welcome: {self.ftp.getwelcome()[:50]}...")
                self.is_connected = True
                return True
            
            except (socket.timeout, ftplib.all_errors) as e:
                logger.warning(f"FTP connection attempt {attempt} failed: {e}")
                
                if attempt < self.retries:
                    backoff_time = self.retry_backoff ** (attempt - 1)
                    logger.info(f"Retrying in {backoff_time}s...")
                    time.sleep(backoff_time)
                else:
                    logger.error(f"FTP connection failed after {self.retries} attempts")
                    self.is_connected = False
                    return False
        
        return False
    
    def disconnect(self):
        """Disconnect from FTP server"""
        try:
            if self.ftp and self.is_connected:
                self.ftp.quit()
                logger.info("FTP disconnected")
        except Exception as e:
            logger.warning(f"Error during FTP disconnect: {e}")
        finally:
            self.is_connected = False
            self.ftp = None
    
    def upload_file(self, local_path: str, remote_path: str) -> FTPUploadResult:
        """
        Upload a file to FTP server
        
        Args:
            local_path: Local file path
            remote_path: Remote file path (e.g., "/index.html" or "/app/file.php")
        
        Returns:
            FTPUploadResult object
        """
        local_file = Path(local_path)
        start_time = time.time()
        
        if not local_file.exists():
            error = f"Local file not found: {local_path}"
            logger.error(error)
            return FTPUploadResult(
                file_path=str(local_path),
                remote_path=remote_path,
                success=False,
                size_bytes=0,
                time_seconds=0,
                error=error
            )
        
        file_size = local_file.stat().st_size
        
        try:
            # Ensure remote directory exists
            self._ensure_remote_dir(remote_path)
            
            # Upload file
            logger.debug(f"Uploading {local_path} → {remote_path} ({file_size} bytes)...")
            
            with open(local_file, 'rb') as f:
                self.ftp.storbinary(f'STOR {remote_path}', f, blocksize=8192)
            
            elapsed = time.time() - start_time
            logger.info(f"✓ Uploaded {local_path} ({file_size / 1024:.1f} KB in {elapsed:.2f}s)")
            
            return FTPUploadResult(
                file_path=str(local_path),
                remote_path=remote_path,
                success=True,
                size_bytes=file_size,
                time_seconds=elapsed
            )
        
        except (socket.timeout, ftplib.all_errors) as e:
            elapsed = time.time() - start_time
            error = f"Upload failed: {e}"
            logger.error(f"✗ {error} | {local_path}")
            
            return FTPUploadResult(
                file_path=str(local_path),
                remote_path=remote_path,
                success=False,
                size_bytes=file_size,
                time_seconds=elapsed,
                error=error
            )
    
    def mkdir(self, remote_dir: str) -> bool:
        """Create remote directory"""
        try:
            self.ftp.mkd(remote_dir)
            logger.debug(f"Created FTP directory: {remote_dir}")
            return True
        except ftplib.error_perm as e:
            if "exist" in str(e).lower():
                return True  # Directory already exists
            logger.warning(f"Failed to create directory {remote_dir}: {e}")
            return False
    
    def _ensure_remote_dir(self, remote_path: str):
        """Ensure remote directory exists (create if needed)"""
        remote_dir = str(Path(remote_path).parent)
        
        if remote_dir and remote_dir != '/':
            try:
                # Try to change to directory (check if exists)
                self.ftp.cwd(remote_dir)
                self.ftp.cwd('/')  # Go back to root
            except ftplib.error_perm:
                # Directory doesn't exist, create it
                logger.debug(f"Creating remote directory: {remote_dir}")
                parts = remote_dir.split('/')
                for part in parts:
                    if part:
                        try:
                            self.ftp.cwd(part)
                        except ftplib.error_perm:
                            self.ftp.mkd(part)
                            self.ftp.cwd(part)
                self.ftp.cwd('/')
    
    def exists(self, remote_path: str) -> bool:
        """Check if remote file exists"""
        try:
            self.ftp.size(remote_path)
            return True
        except ftplib.error_perm:
            return False
    
    def get_size(self, remote_path: str) -> Optional[int]:
        """Get remote file size"""
        try:
            return self.ftp.size(remote_path)
        except ftplib.error_perm:
            return None
    
    def list_dir(self, remote_path: str = '/') -> list:
        """List files in remote directory"""
        try:
            return self.ftp.nlst(remote_path)
        except ftplib.error_perm as e:
            logger.warning(f"Failed to list {remote_path}: {e}")
            return []
    
    def __enter__(self):
        """Context manager entry"""
        self.connect()
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb):
        """Context manager exit"""
        self.disconnect()
    
    def __del__(self):
        """Destructor"""
        if self.is_connected:
            self.disconnect()
