"""
Change Analyzer - Detect changed files using Git or Timestamp comparison
"""

import subprocess
from pathlib import Path
from typing import List, Tuple, Optional, Set
from dataclasses import dataclass
from core.ignore_manager import IgnoreManager
from utils.logger import get_logger
import hashlib

logger = get_logger(__name__)


@dataclass
class ChangedFile:
    """Represents a changed file"""
    local_path: Path
    relative_path: str
    file_type: str  # 'A' (added), 'M' (modified), 'D' (deleted), 'new', 'changed'
    size: int
    hash: Optional[str] = None


class ChangeAnalyzer:
    """Analyze changes using Git status or timestamp comparison"""
    
    def __init__(self, project_root: str = ".", ignore_manager: Optional[IgnoreManager] = None):
        """
        Initialize change analyzer
        
        Args:
            project_root: Project root directory
            ignore_manager: IgnoreManager instance (creates if None)
        """
        self.project_root = Path(project_root)
        self.ignore_manager = ignore_manager or IgnoreManager(project_root)
        self.last_deploy_marker = self.project_root / ".last_deploy_marker"
    
    def analyze_git_changes(self) -> List[ChangedFile]:
        """
        Detect changes using git status
        
        Returns:
            List of ChangedFile objects
        """
        logger.info("Analyzing changes via Git status...")
        
        try:
            # Get both staged and unstaged changes
            result = subprocess.run(
                ["git", "status", "--porcelain"],
                cwd=self.project_root,
                capture_output=True,
                text=True,
                timeout=10
            )
            
            if result.returncode != 0:
                raise RuntimeError(f"Git error: {result.stderr}")
            
            changed_files = []
            lines = result.stdout.strip().split('\n')
            
            for line in lines:
                if not line.strip():
                    continue
                
                # Format: " M file.txt" or "M  file.txt"
                status = line[:2].strip()
                file_path = line[3:].strip()
                
                # Skip deleted files (for deployment purposes)
                if status == 'D':
                    logger.debug(f"Skipping deleted file: {file_path}")
                    continue
                
                # Check ignore rules
                if self.ignore_manager.should_ignore(file_path):
                    logger.debug(f"Ignoring: {file_path}")
                    continue
                
                full_path = self.project_root / file_path
                
                if full_path.exists():
                    size = full_path.stat().st_size
                    file_hash = self._compute_hash(full_path)
                    
                    changed_files.append(ChangedFile(
                        local_path=full_path,
                        relative_path=file_path,
                        file_type='M' if status == 'M' else 'A',
                        size=size,
                        hash=file_hash
                    ))
            
            logger.info(f"Found {len(changed_files)} changed files via Git")
            return changed_files
        
        except FileNotFoundError:
            logger.error("Git not found in PATH")
            raise
        except subprocess.TimeoutExpired:
            logger.error("Git status check timed out")
            raise
        except Exception as e:
            logger.error(f"Git analysis failed: {e}")
            raise
    
    def analyze_timestamp_changes(self) -> List[ChangedFile]:
        """
        Detect changes using timestamp comparison vs. last deploy marker
        
        Returns:
            List of ChangedFile objects
        """
        logger.info("Analyzing changes via timestamp comparison...")
        
        if not self.last_deploy_marker.exists():
            logger.warning("No .last_deploy_marker found, scanning all files...")
            marker_time = 0
        else:
            marker_time = self.last_deploy_marker.stat().st_mtime
            logger.info(f"Comparing against last deploy marker: {marker_time}")
        
        changed_files = []
        
        # Scan all files in project
        for full_path in self.project_root.rglob('*'):
            if not full_path.is_file():
                continue
            
            relative_path = full_path.relative_to(self.project_root).as_posix()
            
            # Check ignore rules
            if self.ignore_manager.should_ignore(relative_path):
                logger.debug(f"Ignoring: {relative_path}")
                continue
            
            file_mtime = full_path.stat().st_mtime
            
            # Check if file is newer than marker
            if file_mtime > marker_time:
                size = full_path.stat().st_size
                file_hash = self._compute_hash(full_path)
                
                changed_files.append(ChangedFile(
                    local_path=full_path,
                    relative_path=relative_path,
                    file_type='changed',
                    size=size,
                    hash=file_hash
                ))
        
        logger.info(f"Found {len(changed_files)} changed files via timestamp")
        return changed_files
    
    def analyze_changes(self, mode: str = "git") -> List[ChangedFile]:
        """
        Analyze changes based on mode
        
        Args:
            mode: 'git' or 'timestamp'
        
        Returns:
            List of ChangedFile objects
        """
        if mode.lower() == "git":
            return self.analyze_git_changes()
        elif mode.lower() == "timestamp":
            return self.analyze_timestamp_changes()
        else:
            raise ValueError(f"Unknown change detection mode: {mode}")
    
    def update_deploy_marker(self):
        """Update .last_deploy_marker to current time"""
        import time
        self.last_deploy_marker.touch()
        logger.info(f"Updated deploy marker: {self.last_deploy_marker}")
    
    def _compute_hash(self, file_path: Path, algorithm: str = "md5") -> str:
        """Compute file hash"""
        hash_obj = hashlib.new(algorithm)
        
        try:
            with open(file_path, 'rb') as f:
                for chunk in iter(lambda: f.read(4096), b''):
                    hash_obj.update(chunk)
            return hash_obj.hexdigest()
        except Exception as e:
            logger.warning(f"Failed to hash {file_path}: {e}")
            return ""
    
    def get_changed_by_category(self, changed_files: List[ChangedFile]) -> dict:
        """Categorize changed files by target (frontend, backend, database, etc.)"""
        categories = {
            'frontend': [],
            'backend': [],
            'database': [],
            'config': [],
            'other': []
        }
        
        for cf in changed_files:
            path_lower = cf.relative_path.lower()
            
            if path_lower.startswith('asianwokandgrill.in/'):
                categories['frontend'].append(cf)
            elif path_lower.startswith(('app/', 'bootstrap/', 'config/')):
                categories['backend'].append(cf)
            elif path_lower.startswith('database/migrations/'):
                categories['database'].append(cf)
            elif path_lower in ('.env', '.htaccess'):
                categories['config'].append(cf)
            else:
                categories['other'].append(cf)
        
        return categories
    
    def summary(self, changed_files: List[ChangedFile]) -> str:
        """Generate summary of changes"""
        total_size = sum(cf.size for cf in changed_files)
        categories = self.get_changed_by_category(changed_files)
        
        summary = f"""
Change Summary:
  Total files: {len(changed_files)}
  Total size: {total_size / (1024*1024):.2f} MB
  
  By category:
    - Frontend: {len(categories['frontend'])} files
    - Backend: {len(categories['backend'])} files
    - Database: {len(categories['database'])} files
    - Config: {len(categories['config'])} files
    - Other: {len(categories['other'])} files
"""
        return summary
