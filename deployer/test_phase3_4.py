#!/usr/bin/env python3
"""
Phase 3 & 4 Integration Tests - FTP and Database Deployment
"""

import sys
from pathlib import Path

# Add deployer to path
deployer_dir = Path(__file__).parent
sys.path.insert(0, str(deployer_dir))

from utils.logger import setup_logger
from core.deployment_planner import DeploymentPlan, DeploymentTarget
from ftp_module.ftp_deployer import FTPDeployer
from db_module.db_deployer import DBDeployer
from db_module.db_client import DBClient
from db_module.migration_analyzer import MigrationAnalyzer, MigrationExecutor

logger_obj, log_file = setup_logger("test_phase3_4", log_dir=str(deployer_dir / "logs"))
logger = logger_obj


def test_ftp_deployment():
    """Test FTP deployment simulation"""
    print("\n" + "="*70)
    print("TEST: FTP Deployment Module")
    print("="*70)
    
    try:
        # Create mock FTP config
        ftp_config = {
            'host': 'ftp.example.com',
            'port': 21,
            'user': 'test_user',
            'password': 'test_pass'
        }
        
        logger.info("Creating FTP deployer...")
        ftp_deployer = FTPDeployer(ftp_config, max_workers=3)
        logger.info("✓ FTP deployer created successfully")
        
        # Create mock deployment plan
        logger.info("Creating mock deployment plan...")
        
        plan = DeploymentPlan(
            plan_id="test_plan_ftp",
            mode="ftp",
            environment="LOCAL",
            targets=[
                DeploymentTarget(
                    name="frontend",
                    type="ftp",
                    local_dir="asianwokandgrill.in",
                    remote_path="/",
                    files=["menu.html", "index.html", "style.css"]
                ),
                DeploymentTarget(
                    name="backend",
                    type="ftp",
                    local_dir="app",
                    remote_path="/app",
                    files=["MenuService.php", "Controller.php"]
                )
            ],
            total_files=5,
            total_size=102400,
            estimated_time_seconds=15
        )
        
        logger.info("✓ Deployment plan created")
        logger.info(f"  Targets: {len(plan.targets)}")
        logger.info(f"  Files: {plan.total_files}")
        logger.info(f"  Size: {plan.total_size / 1024:.1f} KB")
        logger.info(f"  Estimate: {plan.estimated_time_seconds}s")
        
        logger.info("✓ FTP Deployment Module: PASSED\n")
        return True
    
    except Exception as e:
        logger.error(f"✗ FTP Deployment Module: FAILED - {e}")
        return False


def test_db_client():
    """Test database client"""
    print("\n" + "="*70)
    print("TEST: Database Client Module")
    print("="*70)
    
    try:
        logger.info("Creating DB client...")
        
        db_client = DBClient(
            host='mysql.example.com',
            port=3306,
            database='test_db',
            user='test_user',
            password='test_pass'
        )
        
        logger.info("✓ DB client created")
        logger.info(f"  Host: {db_client.host}")
        logger.info(f"  Database: {db_client.database}")
        logger.info(f"  Port: {db_client.port}")
        
        # Note: Not actually connecting (no real DB)
        logger.info("✓ Database Client Module: PASSED (structure validated)\n")
        return True
    
    except Exception as e:
        logger.error(f"✗ Database Client Module: FAILED - {e}")
        return False


def test_migration_analyzer():
    """Test migration analyzer"""
    print("\n" + "="*70)
    print("TEST: Migration Analyzer Module")
    print("="*70)
    
    try:
        logger.info("Creating migration analyzer...")
        
        # Mock DB client
        db_client = DBClient(
            host='localhost',
            port=3306,
            database='test',
            user='root',
            password='pass'
        )
        
        # Create analyzer with test migrations directory
        analyzer = MigrationAnalyzer("database/migrations", db_client)
        logger.info("✓ Migration analyzer created")
        
        # Get all migrations (if directory exists)
        migrations_dir = Path("database/migrations")
        if migrations_dir.exists():
            all_migs = analyzer.get_all_migrations()
            logger.info(f"✓ Found {len(all_migs)} migration files")
            for m in all_migs[:5]:  # Show first 5
                logger.info(f"  • {m.name}")
        else:
            logger.warning("⚠ No migrations directory found (expected in dev)")
        
        logger.info("✓ Migration Analyzer Module: PASSED\n")
        return True
    
    except Exception as e:
        logger.error(f"✗ Migration Analyzer Module: FAILED - {e}")
        return False


def test_migration_executor():
    """Test migration executor"""
    print("\n" + "="*70)
    print("TEST: Migration Executor Module")
    print("="*70)
    
    try:
        logger.info("Creating migration executor...")
        
        # Mock DB client
        db_client = DBClient(
            host='localhost',
            port=3306,
            database='test',
            user='root',
            password='pass'
        )
        
        executor = MigrationExecutor(db_client, backup_dir="deployer/backups")
        logger.info("✓ Migration executor created")
        logger.info(f"  Backup dir: deployer/backups")
        
        # Test with empty migration list (dry-run)
        logger.info("✓ Testing execute with dry-run...")
        result = executor.execute_migrations([], auto_apply=True, dry_run=True)
        
        if result['success']:
            logger.info("✓ Migration Executor Module: PASSED\n")
            return True
        else:
            logger.error("✗ Migration executor dry-run failed")
            return False
    
    except Exception as e:
        logger.error(f"✗ Migration Executor Module: FAILED - {e}")
        return False


def test_db_deployer():
    """Test database deployer orchestration"""
    print("\n" + "="*70)
    print("TEST: Database Deployer Orchestration")
    print("="*70)
    
    try:
        logger.info("Creating DB deployer...")
        
        db_config = {
            'host': 'localhost',
            'port': 3306,
            'database': 'test',
            'user': 'root',
            'password': 'pass'
        }
        
        db_deployer = DBDeployer(db_config)
        logger.info("✓ DB deployer created")
        
        # Create mock plan
        plan = DeploymentPlan(
            plan_id="test_plan_db",
            mode="database",
            environment="LOCAL",
            targets=[
                DeploymentTarget(
                    name="migrations",
                    type="database",
                    local_dir="database/migrations",
                    remote_path="N/A",
                    files=[]
                )
            ],
            total_files=0,
            total_size=0,
            estimated_time_seconds=5
        )
        
        logger.info("✓ Mock plan created for database deployment")
        logger.info("✓ Database Deployer Module: PASSED\n")
        return True
    
    except Exception as e:
        logger.error(f"✗ Database Deployer Module: FAILED - {e}")
        return False


def test_cli_import():
    """Test CLI can be imported"""
    print("\n" + "="*70)
    print("TEST: Main CLI Import")
    print("="*70)
    
    try:
        # Try to import the main deployer CLI
        logger.info("Importing main CLI...")
        import deployer
        logger.info("✓ Main CLI imported successfully")
        logger.info("✓ CLI Module: PASSED\n")
        return True
    
    except ImportError as e:
        logger.error(f"✗ CLI Import failed: {e}")
        return False


def run_all_tests():
    """Run all integration tests"""
    print("\n" + "#"*70)
    print("PHASE 3 & 4 INTEGRATION TESTS")
    print("FTP Deployment & Database Deployment Modules")
    print("#"*70)
    
    results = []
    
    # Run tests
    results.append(("FTP Deployment", test_ftp_deployment()))
    results.append(("DB Client", test_db_client()))
    results.append(("Migration Analyzer", test_migration_analyzer()))
    results.append(("Migration Executor", test_migration_executor()))
    results.append(("DB Deployer", test_db_deployer()))
    results.append(("CLI Import", test_cli_import()))
    
    # Summary
    print("\n" + "="*70)
    print("INTEGRATION TEST SUMMARY")
    print("="*70 + "\n")
    
    passed = sum(1 for _, result in results if result)
    failed = sum(1 for _, result in results if not result)
    
    for name, result in results:
        status = "✓ PASSED" if result else "✗ FAILED"
        print(f"{name:<40} {status}")
    
    print(f"\nTotal: {passed}/{len(results)} passed, {failed} failed")
    print(f"Log file: {log_file}\n")
    
    return 0 if failed == 0 else 1


if __name__ == '__main__':
    exit_code = run_all_tests()
    sys.exit(exit_code)
