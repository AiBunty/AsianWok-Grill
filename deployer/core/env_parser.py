"""
Environment Parser - Parse .env file with profile support (LIVE/LOCAL)
"""

from pathlib import Path
from typing import Dict, Optional
from utils.logger import get_logger

logger = get_logger(__name__)


class EnvParser:
    """Parse and manage .env configuration with profile variants"""
    
    REQUIRED_KEYS = {
        'FTP_HOST', 'FTP_USER', 'FTP_PASS',
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'
    }
    
    def __init__(self, env_file_path: str = ".env"):
        """
        Initialize parser and load .env file
        
        Args:
            env_file_path: Path to .env file (relative or absolute)
        """
        self.env_file = Path(env_file_path)
        self.env_dict: Dict[str, str] = {}
        self.profile: Optional[str] = None
        self._load()
    
    def _load(self):
        """Load and parse .env file"""
        if not self.env_file.exists():
            raise FileNotFoundError(f"Environment file not found: {self.env_file}")
        
        with open(self.env_file, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                
                # Skip empty lines and comments
                if not line or line.startswith('#'):
                    continue
                
                # Parse KEY=VALUE
                if '=' in line:
                    key, value = line.split('=', 1)
                    key = key.strip()
                    value = value.strip()
                    
                    # Remove quotes if present
                    if value.startswith('"') and value.endswith('"'):
                        value = value[1:-1]
                    elif value.startswith("'") and value.endswith("'"):
                        value = value[1:-1]
                    
                    self.env_dict[key] = value
        
        # Detect profile
        self.profile = self.env_dict.get('NK_ENV_PROFILE', 'LIVE').upper()
        logger.info(f"Loaded .env from {self.env_file} | Profile: {self.profile}")
    
    def get(self, key: str, profile: Optional[str] = None, default: str = "") -> str:
        """
        Get environment variable with profile fallback
        
        Strategy:
        1. Try KEY_PROFILE (e.g., FTP_HOST_LIVE)
        2. Fall back to KEY (e.g., FTP_HOST)
        3. Fall back to default
        
        Args:
            key: Base key name
            profile: Profile override (uses stored profile if None)
            default: Default value if not found
        
        Returns:
            Value from .env
        """
        use_profile = (profile or self.profile or "").upper()
        
        # Try profile-specific key first
        if use_profile:
            profile_key = f"{key}_{use_profile}"
            if profile_key in self.env_dict:
                value = self.env_dict[profile_key]
                logger.debug(f"get('{key}', profile='{use_profile}') -> {profile_key}={value[:10]}...")
                return value
        
        # Fall back to base key
        if key in self.env_dict:
            value = self.env_dict[key]
            logger.debug(f"get('{key}') -> {key}={value[:10]}...")
            return value
        
        logger.warning(f"Key not found: {key} (profile: {use_profile}), using default: {default}")
        return default
    
    def get_ftp_config(self, profile: Optional[str] = None) -> Dict[str, str]:
        """Get FTP configuration for profile"""
        return {
            'host': self.get('FTP_HOST', profile),
            'user': self.get('FTP_USER', profile),
            'password': self.get('FTP_PASS', profile),
            'remote_path': self.get('FTP_REMOTE_PATH', profile, '/'),
            'port': self.get('FTP_PORT', profile, '21'),
        }
    
    def get_db_config(self, profile: Optional[str] = None) -> Dict[str, str]:
        """Get Database configuration for profile"""
        return {
            'host': self.get('DB_HOST', profile),
            'port': int(self.get('DB_PORT', profile, '3306')),
            'database': self.get('DB_NAME', profile),
            'user': self.get('DB_USER', profile),
            'password': self.get('DB_PASS', profile),
        }
    
    def validate(self, profile: Optional[str] = None) -> tuple[bool, list]:
        """
        Validate that all required keys are present for profile
        
        Returns:
            (is_valid, list_of_errors)
        """
        use_profile = (profile or self.profile or "").upper()
        errors = []
        
        for key in self.REQUIRED_KEYS:
            profile_key = f"{key}_{use_profile}"
            value = self.env_dict.get(profile_key) or self.env_dict.get(key)
            
            if not value or not value.strip():
                errors.append(f"Missing or empty: {key} (or {profile_key})")
        
        if errors:
            logger.error(f"Environment validation failed for profile '{use_profile}':")
            for err in errors:
                logger.error(f"  - {err}")
        else:
            logger.info(f"Environment validation passed for profile '{use_profile}'")
        
        return (len(errors) == 0, errors)
    
    def get_all(self) -> Dict[str, str]:
        """Return all environment variables as dict"""
        return self.env_dict.copy()
    
    def __str__(self):
        return f"EnvParser(file={self.env_file}, profile={self.profile})"
