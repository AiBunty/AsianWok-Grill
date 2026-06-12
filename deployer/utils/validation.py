"""
Pre-flight Validation - Check deployment readiness
"""

import socket
import subprocess
from pathlib import Path
from typing import List, Tuple
from core.env_parser import EnvParser
from utils.logger import get_logger

logger = get_logger(__name__)


class DeploymentValidator:
    """Validate deployment environment and prerequisites"""
    
    def __init__(self, env_parser: EnvParser, project_root: str = "."):
        """
        Initialize validator
        
        Args:
            env_parser: EnvParser instance
            project_root: Project root directory
        """
        self.env = env_parser
        self.project_root = Path(project_root)
        self.errors: List[str] = []
        self.warnings: List[str] = []
    
    def validate_all(self, check_git: bool = True) -> Tuple[bool, List[str], List[str]]:
        """
        Run all validation checks
        
        Returns:
            (is_valid, errors, warnings)
        """
        self.errors.clear()
        self.warnings.clear()
        
        logger.info("Running pre-flight validation checks...")
        
        # Environment validation
        valid, env_errors = self.env.validate()
        if not valid:
            self.errors.extend(env_errors)
        
        # File structure validation
        self._validate_project_structure()
        
        # FTP connectivity check
        self._validate_ftp_connection()
        
        # Database connectivity check
        self._validate_db_connection()
        
        # Git validation (optional)
        if check_git:
            self._validate_git_status()
        
        # Disk space check for backups
        self._validate_disk_space()
        
        # Summarize results
        self._log_summary()
        
        return (len(self.errors) == 0, self.errors, self.warnings)
    
    def _validate_project_structure(self):
        """Check if required directories exist"""
        logger.info("Checking project structure...")
        
        required_dirs = [
            "asianwokandgrill.in",
            "app",
            "database/migrations",
            "deployer",
        ]
        
        for dir_path in required_dirs:
            full_path = self.project_root / dir_path
            if not full_path.exists():
                self.errors.append(f"Missing directory: {dir_path}")
            else:
                logger.debug(f"✓ Found {dir_path}")
        
        # Check for .env and deployer config
        if not (self.project_root / ".env").exists():
            self.errors.append("Missing .env file")
        
        if not (self.project_root / "deployer" / "deployer_config.yaml").exists():
            self.warnings.append("Missing deployer_config.yaml (will use defaults)")
    
    def _validate_ftp_connection(self):
        """Test FTP connection"""
        logger.info("Testing FTP connectivity...")
        
        try:
            ftp_config = self.env.get_ftp_config()
            host = ftp_config.get('host', '').split(':')[0]
            port = int(ftp_config.get('port', '21'))
            
            if not host:
                self.errors.append("FTP_HOST not configured")
                return
            
            # Quick TCP socket test (faster than full FTP handshake)
            try:
                sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                sock.settimeout(5)
                result = sock.connect_ex((host, port))
                sock.close()
                
                if result == 0:
                    logger.info(f"✓ FTP server {host}:{port} is reachable")
                else:
                    self.errors.append(f"Cannot reach FTP server {host}:{port} (connection refused)")
            except socket.gaierror:
                self.errors.append(f"Cannot resolve FTP hostname: {host}")
            except socket.timeout:
                self.warnings.append(f"FTP server {host}:{port} timeout (may be slow network)")
        
        except Exception as e:
            self.errors.append(f"FTP validation error: {e}")
    
    def _validate_db_connection(self):
        """Test database connection"""
        logger.info("Testing database connectivity...")
        
        try:
            db_config = self.env.get_db_config()
            
            # Try to import MySQL driver
            try:
                import mysql.connector
            except ImportError:
                self.warnings.append("mysql-connector-python not installed. Run: pip install mysql-connector-python")
                return
            
            try:
                conn = mysql.connector.connect(
                    host=db_config['host'],
                    port=db_config['port'],
                    user=db_config['user'],
                    password=db_config['password'],
                    database=db_config['database'],
                    connection_timeout=5
                )
                
                if conn.is_connected():
                    cursor = conn.cursor()
                    cursor.execute("SELECT 1")
                    logger.info(f"✓ Database connection successful ({db_config['host']})")
                    cursor.close()
                    conn.close()
            
            except mysql.connector.Error as e:
                if "Access denied" in str(e):
                    self.errors.append(f"Database authentication failed: check DB_USER/DB_PASS")
                elif "Unknown database" in str(e):
                    self.errors.append(f"Database not found: {db_config['database']}")
                else:
                    self.errors.append(f"Database connection failed: {e}")
        
        except Exception as e:
            self.errors.append(f"Database validation error: {e}")
    
    def _validate_git_status(self):
        """Check for uncommitted changes (warning only)"""
        logger.info("Checking Git status...")
        
        try:
            result = subprocess.run(
                ["git", "status", "--porcelain"],
                cwd=self.project_root,
                capture_output=True,
                text=True,
                timeout=5
            )
            
            if result.returncode == 0:
                changed_count = len([l for l in result.stdout.split('\n') if l.strip()])
                if changed_count > 0:
                    self.warnings.append(f"Git working tree has {changed_count} uncommitted changes")
                else:
                    logger.info("✓ Git working tree is clean")
            else:
                self.warnings.append("Could not check Git status (may not be a Git repo)")
        
        except FileNotFoundError:
            self.warnings.append("Git not found in PATH")
        except subprocess.TimeoutExpired:
            self.warnings.append("Git status check timed out")
        except Exception as e:
            logger.debug(f"Git status check failed: {e}")
    
    def _validate_disk_space(self):
        """Check available disk space for backups"""
        logger.info("Checking disk space for backups...")
        
        try:
            import shutil
            stat = shutil.disk_usage(self.project_root)
            available_mb = stat.free / (1024 * 1024)
            
            if available_mb < 500:
                self.warnings.append(f"Low disk space: {available_mb:.0f} MB available (need ≥500 MB for backups)")
            else:
                logger.debug(f"✓ Disk space OK: {available_mb:.0f} MB available")
        
        except Exception as e:
            logger.debug(f"Disk space check failed: {e}")
    
    def _log_summary(self):
        """Log validation summary"""
        if self.errors:
            logger.error(f"\n❌ Validation FAILED with {len(self.errors)} error(s):")
            for err in self.errors:
                logger.error(f"  - {err}")
        else:
            logger.info("\n✓ Validation PASSED")
        
        if self.warnings:
            logger.warning(f"\n⚠️  {len(self.warnings)} warning(s):")
            for warn in self.warnings:
                logger.warning(f"  - {warn}")
    
    def require_confirmation(self, env_profile: str) -> bool:
        """
        Ask for deployment confirmation if errors exist
        
        Returns: True if user confirms, False to abort
        """
        if self.errors:
            logger.error("\nDeployment BLOCKED due to validation errors.")
            return False
        
        if env_profile.upper() == "LIVE":
            logger.warning(f"\n⚠️  DEPLOYING TO {env_profile} - THIS IS PRODUCTION")
            response = input("\nType 'yes' to proceed to LIVE deployment: ").strip().lower()
            return response == 'yes'
        
        return True
