"""
Migration Analyzer & Executor - Detect and run pending migrations
"""

from pathlib import Path
from typing import List, Tuple, Optional
from dataclasses import dataclass
from db_module.db_client import DBClient
from utils.logger import get_logger

logger = get_logger(__name__)


@dataclass
class Migration:
    """Represents a database migration"""
    name: str  # e.g., "001_initial_schema"
    file_path: Path
    sql_content: str
    applied: bool = False


class MigrationAnalyzer:
    """Analyze and detect pending migrations"""
    
    def __init__(self, migrations_dir: str, db_client: DBClient):
        """
        Initialize migration analyzer
        
        Args:
            migrations_dir: Directory containing .sql migration files
            db_client: DBClient instance for querying applied migrations
        """
        self.migrations_dir = Path(migrations_dir)
        self.db_client = db_client
    
    def get_all_migrations(self) -> List[Migration]:
        """Get all migration files from directory"""
        if not self.migrations_dir.exists():
            logger.warning(f"Migrations directory not found: {self.migrations_dir}")
            return []
        
        migrations = []
        
        for sql_file in sorted(self.migrations_dir.glob("*.sql")):
            try:
                with open(sql_file, 'r', encoding='utf-8') as f:
                    sql_content = f.read()
                
                migration = Migration(
                    name=sql_file.stem,
                    file_path=sql_file,
                    sql_content=sql_content
                )
                migrations.append(migration)
            
            except Exception as e:
                logger.warning(f"Failed to read migration {sql_file.name}: {e}")
        
        logger.info(f"Found {len(migrations)} migration files")
        return migrations
    
    def get_pending_migrations(self) -> List[Migration]:
        """Get migrations that haven't been applied yet"""
        all_migrations = self.get_all_migrations()
        applied = self.db_client.get_applied_migrations()
        
        pending = []
        for migration in all_migrations:
            if migration.name not in applied:
                pending.append(migration)
        
        logger.info(f"Found {len(pending)} pending migration(s)")
        return pending
    
    def get_applied_migrations(self) -> List[Migration]:
        """Get migrations that have been applied"""
        all_migrations = self.get_all_migrations()
        applied_names = self.db_client.get_applied_migrations()
        
        applied = [m for m in all_migrations if m.name in applied_names]
        
        for m in applied:
            m.applied = True
        
        return applied


class MigrationExecutor:
    """Execute pending migrations with interactive confirmation"""
    
    def __init__(self, db_client: DBClient, backup_dir: str = "deployer/backups"):
        """
        Initialize migration executor
        
        Args:
            db_client: DBClient instance
            backup_dir: Directory to store backups
        """
        self.db_client = db_client
        self.backup_dir = Path(backup_dir)
        self.backup_dir.mkdir(parents=True, exist_ok=True)
    
    def execute_migrations(self, pending_migrations: List[Migration], 
                          auto_apply: bool = False, dry_run: bool = False) -> dict:
        """
        Execute pending migrations interactively or automatically
        
        Args:
            pending_migrations: List of Migration objects to apply
            auto_apply: If True, apply all without confirmation
            dry_run: If True, don't actually apply
        
        Returns:
            Execution summary dict
        """
        logger.info(f"\n{'='*70}")
        logger.info("DATABASE MIGRATION EXECUTION")
        logger.info(f"{'='*70}\n")
        
        if not pending_migrations:
            logger.info("No pending migrations")
            return {'success': True, 'applied': 0, 'failed': 0, 'skipped': 0}
        
        logger.info(f"Pending migrations: {len(pending_migrations)}")
        for m in pending_migrations:
            logger.info(f"  • {m.name}")
        
        # Backup database before migrations
        if not dry_run:
            backup_file = self.backup_dir / f"pre_migration_{self._timestamp()}.sql"
            success, message = self.db_client.backup(str(backup_file), compress=True)
            
            if not success:
                logger.error(f"Backup failed: {message}")
                return {'success': False, 'error': message, 'applied': 0, 'failed': 0}
            
            logger.info(f"✓ Pre-migration backup created\n")
        
        # Execute migrations
        applied = 0
        failed = 0
        skipped = 0
        errors = []
        
        for migration in pending_migrations:
            if dry_run:
                logger.info(f"[DRY] Would apply: {migration.name}")
                skipped += 1
                continue
            
            # Ask for confirmation (unless auto_apply)
            if not auto_apply:
                response = input(f"\nApply migration '{migration.name}'? (yes/no/all): ").strip().lower()
                
                if response == 'all':
                    auto_apply = True
                elif response != 'yes':
                    logger.info(f"Skipped: {migration.name}")
                    skipped += 1
                    continue
            
            # Show SQL preview
            preview_lines = migration.sql_content.split('\n')[:10]
            logger.info(f"\nApplying: {migration.name}")
            logger.debug(f"SQL preview:")
            for line in preview_lines:
                if line.strip() and not line.strip().startswith('--'):
                    logger.debug(f"  {line[:80]}")
            
            # Execute migration
            try:
                success, error_msg = self.db_client.execute_migration(migration.sql_content)
                
                if success:
                    # Record in migrations table
                    recorded = self.db_client.record_migration(migration.name)
                    
                    if recorded:
                        logger.info(f"✓ Applied and recorded: {migration.name}")
                        applied += 1
                    else:
                        logger.error(f"Applied but failed to record: {migration.name}")
                        failed += 1
                        errors.append(f"{migration.name}: Failed to record in DB")
                else:
                    logger.error(f"✗ Failed to apply {migration.name}: {error_msg}")
                    failed += 1
                    errors.append(f"{migration.name}: {error_msg}")
            
            except Exception as e:
                logger.error(f"✗ Exception while applying {migration.name}: {e}")
                failed += 1
                errors.append(f"{migration.name}: {str(e)}")
        
        # Summary
        logger.info(f"\n{'='*70}")
        logger.info("MIGRATION EXECUTION SUMMARY")
        logger.info(f"{'='*70}\n")
        logger.info(f"Applied: {applied}")
        logger.info(f"Failed: {failed}")
        logger.info(f"Skipped: {skipped}")
        
        if errors:
            logger.warning("Errors:")
            for error in errors:
                logger.warning(f"  • {error}")
        
        logger.info(f"\n{'='*70}\n")
        
        return {
            'success': failed == 0,
            'applied': applied,
            'failed': failed,
            'skipped': skipped,
            'errors': errors
        }
    
    def _timestamp(self) -> str:
        """Get current timestamp for file naming"""
        from datetime import datetime
        return datetime.now().strftime("%Y%m%d_%H%M%S")
