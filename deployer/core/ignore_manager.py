"""
Ignore Manager - Merge .gitignore, .deployignore, and hardcoded patterns
"""

from pathlib import Path
from typing import List, Optional, Set, Callable
import fnmatch
from utils.logger import get_logger

logger = get_logger(__name__)


class IgnoreManager:
    """Manage ignore patterns from multiple sources (git, deploy, hardcoded)"""
    
    # Hardcoded patterns to always ignore
    HARDCODED_PATTERNS = [
        "node_modules/",
        ".git/",
        ".vscode/",
        "__pycache__/",
        "*.pyc",
        ".DS_Store",
        "deployer/",
        "*.log",
        "*.tmp",
        ".env.local",
        ".env.*.local",
    ]
    
    def __init__(self, project_root: str = ".", gitignore_path: Optional[str] = None, 
                 deployignore_path: Optional[str] = None):
        """
        Initialize ignore manager
        
        Args:
            project_root: Project root directory
            gitignore_path: Path to .gitignore (default: project_root/.gitignore)
            deployignore_path: Path to .deployignore (default: deployer/.deployignore)
        """
        self.project_root = Path(project_root)
        self.gitignore_path = Path(gitignore_path or self.project_root / ".gitignore")
        self.deployignore_path = Path(deployignore_path or self.project_root / "deployer" / ".deployignore")
        
        self.patterns: List[tuple[str, bool]] = []  # (pattern, is_negation)
        self.negation_patterns: Set[str] = set()
        
        self._load_patterns()
    
    def _load_patterns(self):
        """Load patterns from all sources"""
        # Load hardcoded patterns
        for pattern in self.HARDCODED_PATTERNS:
            self._add_pattern(pattern, "hardcoded")
        
        # Load .gitignore
        if self.gitignore_path.exists():
            self._load_file(self.gitignore_path, "gitignore")
        else:
            logger.warning(f"Not found: {self.gitignore_path}")
        
        # Load .deployignore
        if self.deployignore_path.exists():
            self._load_file(self.deployignore_path, "deployignore")
        else:
            logger.warning(f"Not found: {self.deployignore_path}")
        
        logger.info(f"Loaded {len(self.patterns)} ignore patterns (hardcoded: {len(self.HARDCODED_PATTERNS)})")
    
    def _load_file(self, file_path: Path, source: str):
        """Load patterns from a file"""
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                count = 0
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#'):
                        self._add_pattern(line, source)
                        count += 1
                logger.debug(f"Loaded {count} patterns from {source}")
        except Exception as e:
            logger.error(f"Failed to load {source} from {file_path}: {e}")
    
    def _add_pattern(self, pattern: str, source: str):
        """Add a pattern to the list"""
        pattern = pattern.strip()
        
        # Handle negation patterns (!)
        is_negation = pattern.startswith('!')
        if is_negation:
            pattern = pattern[1:].strip()
            self.negation_patterns.add(pattern)
        
        if pattern and not pattern.startswith('#'):
            self.patterns.append((pattern, is_negation))
    
    def should_ignore(self, relative_path: str) -> bool:
        """
        Check if a path should be ignored
        
        Returns: True if ignored, False if should be deployed
        """
        relative_path = Path(relative_path).as_posix()
        
        ignored = False
        
        # Apply patterns in order (later patterns override earlier ones)
        for pattern, is_negation in self.patterns:
            if self._matches_pattern(relative_path, pattern):
                ignored = not is_negation  # Negation reverses the decision
        
        return ignored
    
    def _matches_pattern(self, path: str, pattern: str) -> bool:
        """Check if path matches fnmatch pattern"""
        path = path.rstrip('/')
        pattern = pattern.rstrip('/')
        
        # Handle directory patterns (ending with /)
        if pattern.endswith('/'):
            pattern = pattern.rstrip('/') + '/**'
            return fnmatch.fnmatch(path, pattern) or fnmatch.fnmatch(path + '/', pattern + '/')
        
        # Direct match
        if fnmatch.fnmatch(path, pattern):
            return True
        
        # Check if any parent directory matches
        parts = path.split('/')
        for i in range(len(parts)):
            subpath = '/'.join(parts[:i+1])
            if fnmatch.fnmatch(subpath, pattern):
                return True
        
        return False
    
    def get_ignored_paths(self, directory: str) -> List[str]:
        """Get all ignored paths in a directory (recursive)"""
        ignored = []
        for path in Path(directory).rglob('*'):
            relative = path.relative_to(directory).as_posix()
            if self.should_ignore(relative):
                ignored.append(relative)
        return ignored
    
    def get_filter_func(self) -> Callable[[str], bool]:
        """Return a filter function for use with itertools.filterfalse"""
        return lambda path: self.should_ignore(path)
    
    def add_pattern(self, pattern: str, is_negation: bool = False):
        """Add pattern at runtime"""
        self.patterns.append((pattern, is_negation))
        logger.debug(f"Added ignore pattern: {pattern}")
    
    def summary(self) -> str:
        """Return summary of loaded patterns"""
        return f"""
Ignore Manager Summary:
  - Hardcoded patterns: {len(self.HARDCODED_PATTERNS)}
  - .gitignore patterns: {sum(1 for line in open(self.gitignore_path) if line.strip() and not line.startswith('#')) if self.gitignore_path.exists() else 0}
  - .deployignore patterns: {sum(1 for line in open(self.deployignore_path) if line.strip() and not line.startswith('#')) if self.deployignore_path.exists() else 0}
  - Total patterns: {len(self.patterns)}
  - Negation patterns: {len(self.negation_patterns)}
"""
