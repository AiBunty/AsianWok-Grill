"""
Deployment Planner - Plan deployment based on changed files and mode
"""

from typing import List, Dict, Any
from dataclasses import dataclass, asdict
import json
from pathlib import Path
from core.change_analyzer import ChangedFile
from utils.logger import get_logger

logger = get_logger(__name__)


@dataclass
class DeploymentTarget:
    """Represents a deployment target"""
    name: str
    type: str  # 'ftp' or 'database'
    local_dir: str
    remote_path: str
    file_extensions: List[str]
    files: List[ChangedFile]


@dataclass
class DeploymentPlan:
    """Complete deployment plan"""
    plan_id: str
    mode: str  # 'ftp', 'database', 'backend', 'full'
    environment: str
    targets: List[DeploymentTarget]
    total_files: int
    total_size: int
    estimated_time_seconds: float
    created_at: str


class DeploymentPlanner:
    """Plan deployment based on changed files"""
    
    # FTP target definitions
    FTP_TARGETS = {
        'frontend': {
            'local_dir': 'asianwokandgrill.in',
            'remote_path': '/',
            'extensions': ['.html', '.htm', '.css', '.js', '.svg', '.ico', '.xml', '.json'],
        },
        'backend': {
            'local_dir': 'app',
            'remote_path': '/app',
            'extensions': ['.php', '.htaccess'],
        },
        'config': {
            'local_dir': '',
            'remote_path': '/',
            'extensions': ['.env', '.htaccess'],
        },
    }
    
    # Size thresholds for speed estimation (MB/s)
    UPLOAD_SPEED_MB_PER_SEC = 0.1  # Conservative estimate for shared hosting (100 KB/s)
    DB_MIGRATION_TIME_PER_FILE_SEC = 5  # Estimate per migration
    
    def __init__(self, project_root: str = "."):
        """Initialize planner"""
        self.project_root = Path(project_root)
    
    def plan_deployment(self, changed_files: List[ChangedFile], mode: str = "full", 
                       environment: str = "LIVE") -> DeploymentPlan:
        """
        Create deployment plan based on mode
        
        Args:
            changed_files: List of ChangedFile objects
            mode: 'ftp', 'database', 'backend', 'full'
            environment: 'LIVE' or 'LOCAL'
        
        Returns:
            DeploymentPlan object
        """
        logger.info(f"Planning deployment | Mode: {mode} | Environment: {environment}")
        
        targets = []
        total_size = 0
        
        if mode.lower() == "ftp" or mode.lower() == "full":
            ftp_targets = self._plan_ftp_deployment(changed_files)
            targets.extend(ftp_targets)
            total_size += sum(sum(cf.size for cf in t.files) for t in ftp_targets)
        
        if mode.lower() == "database" or mode.lower() == "full":
            db_targets = self._plan_database_deployment(changed_files)
            targets.extend(db_targets)
        
        if mode.lower() == "backend" or mode.lower() == "full":
            backend_targets = self._plan_backend_deployment(changed_files)
            targets.extend(backend_targets)
            total_size += sum(sum(cf.size for cf in t.files) for t in backend_targets)
        
        # Estimate deployment time
        estimated_time = self._estimate_time(total_size, len(targets))
        
        # Create plan
        plan = DeploymentPlan(
            plan_id=self._generate_plan_id(),
            mode=mode.lower(),
            environment=environment.upper(),
            targets=targets,
            total_files=sum(len(t.files) for t in targets),
            total_size=total_size,
            estimated_time_seconds=estimated_time,
            created_at=self._get_timestamp()
        )
        
        logger.info(f"Deployment plan created: {plan.total_files} files, {plan.total_size / (1024*1024):.2f} MB, ~{plan.estimated_time_seconds:.0f}s")
        
        return plan
    
    def _plan_ftp_deployment(self, changed_files: List[ChangedFile]) -> List[DeploymentTarget]:
        """Plan FTP deployment targets"""
        targets = []
        
        # Group files by target
        frontend_files = [cf for cf in changed_files if cf.relative_path.startswith('asianwokandgrill.in/')]
        backend_files = [cf for cf in changed_files if cf.relative_path.startswith('app/')]
        config_files = [cf for cf in changed_files if cf.relative_path in ('.env', '.htaccess')]
        
        if frontend_files:
            targets.append(DeploymentTarget(
                name='Frontend',
                type='ftp',
                local_dir='asianwokandgrill.in',
                remote_path='/',
                file_extensions=self.FTP_TARGETS['frontend']['extensions'],
                files=frontend_files
            ))
        
        if backend_files:
            targets.append(DeploymentTarget(
                name='Backend Code',
                type='ftp',
                local_dir='app',
                remote_path='/app',
                file_extensions=self.FTP_TARGETS['backend']['extensions'],
                files=backend_files
            ))
        
        if config_files:
            targets.append(DeploymentTarget(
                name='Configuration',
                type='ftp',
                local_dir='',
                remote_path='/',
                file_extensions=self.FTP_TARGETS['config']['extensions'],
                files=config_files
            ))
        
        return targets
    
    def _plan_database_deployment(self, changed_files: List[ChangedFile]) -> List[DeploymentTarget]:
        """Plan database deployment targets"""
        targets = []
        
        # Find migration files
        migration_files = [cf for cf in changed_files if cf.relative_path.startswith('database/migrations/')]
        
        if migration_files:
            targets.append(DeploymentTarget(
                name='Database Migrations',
                type='database',
                local_dir='database/migrations',
                remote_path='',
                file_extensions=['.sql'],
                files=migration_files
            ))
        
        return targets
    
    def _plan_backend_deployment(self, changed_files: List[ChangedFile]) -> List[DeploymentTarget]:
        """Plan backend (PHP code) deployment"""
        targets = []
        
        backend_files = [cf for cf in changed_files if cf.relative_path.startswith(('app/', 'bootstrap/', 'config/'))]
        
        if backend_files:
            targets.append(DeploymentTarget(
                name='Backend Services',
                type='ftp',
                local_dir='app',
                remote_path='/app',
                file_extensions=['.php', '.env'],
                files=backend_files
            ))
        
        return targets
    
    def _estimate_time(self, total_size: int, target_count: int) -> float:
        """Estimate deployment time in seconds"""
        # FTP upload time
        ftp_time = (total_size / (1024 * 1024)) / self.UPLOAD_SPEED_MB_PER_SEC
        
        # Add overhead per target (connection, directory creation)
        overhead_per_target = 2  # seconds
        
        # Total estimate
        return ftp_time + (target_count * overhead_per_target)
    
    def _generate_plan_id(self) -> str:
        """Generate unique plan ID"""
        from datetime import datetime
        return datetime.now().strftime("%Y%m%d_%H%M%S")
    
    def _get_timestamp(self) -> str:
        """Get current ISO timestamp"""
        from datetime import datetime
        return datetime.now().isoformat()
    
    def preview(self, plan: DeploymentPlan) -> str:
        """Generate human-readable preview of deployment plan"""
        preview = f"""
╔════════════════════════════════════════════════════════════════╗
║              DEPLOYMENT PLAN PREVIEW                            ║
╚════════════════════════════════════════════════════════════════╝

Plan ID:        {plan.plan_id}
Mode:           {plan.mode.upper()}
Environment:    {plan.environment}
Total Files:    {plan.total_files}
Total Size:     {plan.total_size / (1024*1024):.2f} MB
Est. Time:      {plan.estimated_time_seconds:.0f} seconds (~{plan.estimated_time_seconds / 60:.1f} minutes)

TARGETS:
"""
        
        for i, target in enumerate(plan.targets, 1):
            target_size = sum(f.size for f in target.files)
            preview += f"\n  {i}. {target.name} ({target.type})\n"
            preview += f"     Remote: {target.remote_path}\n"
            preview += f"     Files: {len(target.files)} | Size: {target_size / (1024*1024):.2f} MB\n"
            
            # Show first 5 files
            for j, f in enumerate(target.files[:5], 1):
                preview += f"       • {f.relative_path} ({f.size / 1024:.1f} KB)\n"
            
            if len(target.files) > 5:
                preview += f"       ... and {len(target.files) - 5} more files\n"
        
        preview += "\n" + "="*62 + "\n"
        
        return preview
    
    def save_plan(self, plan: DeploymentPlan, output_dir: str = "deployer/logs") -> str:
        """Save plan to JSON file"""
        output_path = Path(output_dir)
        output_path.mkdir(parents=True, exist_ok=True)
        
        plan_file = output_path / f"plan_{plan.plan_id}.json"
        
        # Convert to dict for JSON serialization
        plan_dict = asdict(plan)
        plan_dict['targets'] = [
            {
                'name': t.name,
                'type': t.type,
                'local_dir': t.local_dir,
                'remote_path': t.remote_path,
                'file_extensions': t.file_extensions,
                'files': [
                    {
                        'relative_path': f.relative_path,
                        'file_type': f.file_type,
                        'size': f.size,
                        'hash': f.hash
                    }
                    for f in t.files
                ]
            }
            for t in plan.targets
        ]
        
        with open(plan_file, 'w', encoding='utf-8') as f:
            json.dump(plan_dict, f, indent=2)
        
        logger.info(f"Plan saved to {plan_file}")
        return str(plan_file)
