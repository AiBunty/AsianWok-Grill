"""
Database Deployer - Orchestrate database deployment and migrations
"""

from typing import List, Dict, Optional
from db_module.db_client import DBClient
from db_module.migration_analyzer import MigrationAnalyzer, MigrationExecutor, Migration
from core.deployment_planner import DeploymentPlan
from utils.logger import get_logger

logger = get_logger(__name__)


class DBDeployer:
    """Handle database deployment with migrations"""
    
    def __init__(self, db_config: Dict[str, str], migrations_dir: str = "database/migrations",
                 backup_dir: str = "deployer/backups"):
        """
        Initialize database deployer
        
        Args:
            db_config: Database configuration dict
            migrations_dir: Directory containing migration files
            backup_dir: Directory for backups
        """
        self.db_config = db_config
        self.migrations_dir = migrations_dir
        self.backup_dir = backup_dir
        self.db_client: Optional[DBClient] = None
    
    def deploy(self, plan: DeploymentPlan, auto_apply: bool = False, 
               dry_run: bool = False) -> Dict:
        """
        Execute database deployment based on plan
        
        Args:
            plan: DeploymentPlan object
            auto_apply: If True, apply migrations without confirmation
            dry_run: If True, don't actually apply
        
        Returns:
            Deployment result dict
        """
        logger.info(f"\n{'='*70}")
        logger.info(f"DATABASE DEPLOYMENT | Mode: {plan.mode.upper()} | Env: {plan.environment}")
        logger.info(f"{'='*70}\n")
        
        # Filter database targets only
        db_targets = [t for t in plan.targets if t.type == 'database']
        
        if not db_targets:
            logger.info("No database targets in deployment plan")
            return self._summary([], dry_run)
        
        # Connect to database
        try:
            self.db_client = DBClient(
                host=self.db_config['host'],
                port=self.db_config['port'],
                database=self.db_config['database'],
                user=self.db_config['user'],
                password=self.db_config['password']
            )
            
            if not self.db_client.connect():
                error = "Failed to connect to database"
                logger.error(error)
                return self._summary([], dry_run, errors=[error])
            
            # Analyze pending migrations
            analyzer = MigrationAnalyzer(self.migrations_dir, self.db_client)
            pending = analyzer.get_pending_migrations()
            
            if not pending:
                logger.info("No pending migrations")
                self.db_client.disconnect()
                return self._summary([], dry_run)
            
            logger.info(f"Found {len(pending)} pending migration(s):")
            for m in pending:
                logger.info(f"  • {m.name}")
            
            # Execute migrations
            executor = MigrationExecutor(self.db_client, self.backup_dir)
            result = executor.execute_migrations(pending, auto_apply=auto_apply, dry_run=dry_run)
            
            self.db_client.disconnect()
            return self._summary(pending, dry_run, result=result)
        
        except Exception as e:
            error = f"Database deployment error: {e}"
            logger.error(error)
            if self.db_client:
                self.db_client.disconnect()
            return self._summary([], dry_run, errors=[error])
    
    def _summary(self, migrations: List[Migration], dry_run: bool = False, 
                result: Dict = None, errors: List[str] = None) -> Dict:
        """Generate deployment summary"""
        
        logger.info(f"\n{'='*70}")
        logger.info("DATABASE DEPLOYMENT SUMMARY")
        logger.info(f"{'='*70}\n")
        
        if errors:
            for error in errors:
                logger.error(f"✗ {error}")
        
        if dry_run:
            logger.info("DRY-RUN MODE - No migrations were applied")
            if migrations:
                logger.info(f"Would apply {len(migrations)} migration(s):")
                for m in migrations:
                    logger.info(f"  • {m.name}")
        elif result:
            logger.info(f"Applied: {result.get('applied', 0)}")
            logger.info(f"Failed: {result.get('failed', 0)}")
            logger.info(f"Skipped: {result.get('skipped', 0)}")
            
            if result.get('errors'):
                logger.warning("Errors:")
                for error in result['errors']:
                    logger.warning(f"  • {error}")
        else:
            logger.info("No migrations to process")
        
        logger.info(f"\n{'='*70}\n")
        
        return {
            'success': not errors and (result is None or result.get('success', True)),
            'dry_run': dry_run,
            'total_migrations': len(migrations),
            'applied': result.get('applied', 0) if result else 0,
            'failed': result.get('failed', 0) if result else 0,
            'skipped': result.get('skipped', 0) if result else 0,
            'errors': errors or result.get('errors', []) if result else []
        }
