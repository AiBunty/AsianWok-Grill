#!/usr/bin/env python3
"""
Asian Wok & Grill Deployer - Main CLI Entry Point
"""

import sys
import argparse
from pathlib import Path

# Add deployer to path
deployer_dir = Path(__file__).parent
sys.path.insert(0, str(deployer_dir))

from core.env_parser import EnvParser
from core.ignore_manager import IgnoreManager
from core.change_analyzer import ChangeAnalyzer
from core.deployment_planner import DeploymentPlanner
from ftp_module.ftp_deployer import FTPDeployer
from db_module.db_deployer import DBDeployer
from utils.logger import setup_logger, cleanup_old_logs
from utils.validation import DeploymentValidator


class Deployer:
    """Main deployer orchestrator"""
    
    def __init__(self, project_root: str = ".", env_file: str = ".env"):
        """Initialize deployer"""
        self.project_root = Path(project_root)
        self.log_dir = self.project_root / "deployer" / "logs"
        
        # Setup logging
        self.logger_obj, self.log_file = setup_logger(
            "deployer.cli",
            log_dir=str(self.log_dir),
            log_level="INFO"
        )
        self.logger = self.logger_obj
        
        # Initialize components
        self.env = EnvParser(env_file_path=str(self.project_root / env_file))
        self.ignore_mgr = IgnoreManager(
            project_root=str(self.project_root),
            gitignore_path=str(self.project_root / ".gitignore"),
            deployignore_path=str(deployer_dir / ".deployignore")
        )
        self.change_analyzer = ChangeAnalyzer(
            project_root=str(self.project_root),
            ignore_manager=self.ignore_mgr
        )
        self.planner = DeploymentPlanner(project_root=str(self.project_root))
        self.validator = DeploymentValidator(self.env, project_root=str(self.project_root))
    
    def deploy(self, mode: str, environment: str, detect_mode: str = "git",
               auto_apply: bool = False, dry_run: bool = False):
        """Execute deployment"""
        
        self.logger.info(f"\n{'#'*70}")
        self.logger.info(f"DEPLOYMENT STARTED")
        self.logger.info(f"Mode: {mode.upper()} | Environment: {environment.upper()}")
        self.logger.info(f"Detect: {detect_mode} | DryRun: {dry_run}")
        self.logger.info(f"{'#'*70}\n")
        
        # Run pre-flight validation
        is_valid, errors, warnings = self.validator.validate_all(check_git=(detect_mode=="git"))
        
        if not is_valid:
            self.logger.error("\n❌ Deployment blocked by validation errors")
            return 1
        
        # Ask for confirmation on LIVE
        if not self.validator.require_confirmation(environment):
            self.logger.info("\n⚠️  Deployment cancelled by user")
            return 2
        
        try:
            # Detect changes
            self.logger.info(f"\nDetecting changes via {detect_mode}...")
            changed_files = self.change_analyzer.analyze_changes(mode=detect_mode)
            
            if not changed_files:
                self.logger.warning("\nNo changes detected. Nothing to deploy.")
                return 0
            
            # Plan deployment
            self.logger.info(f"\nPlanning deployment...")
            plan = self.planner.plan_deployment(changed_files, mode=mode, environment=environment)
            
            # Show preview
            preview = self.planner.preview(plan)
            print(preview)
            
            # Ask user confirmation (unless dry-run)
            if not dry_run:
                response = input("Proceed with deployment? (yes/no): ").strip().lower()
                if response != 'yes':
                    self.logger.info("Deployment cancelled by user")
                    return 2
            
            # Execute FTP deployment
            if mode.lower() in ('ftp', 'full', 'backend'):
                self.logger.info("\n" + "="*70)
                self.logger.info("EXECUTING FTP DEPLOYMENT")
                self.logger.info("="*70)
                
                ftp_config = self.env.get_ftp_config(profile=environment)
                ftp_deployer = FTPDeployer(ftp_config, max_workers=5)
                ftp_result = ftp_deployer.deploy(plan, dry_run=dry_run)
                
                self.logger.info(f"\nFTP Result: {'✓ SUCCESS' if ftp_result['success'] else '✗ FAILED'}")
            
            # Execute Database deployment
            if mode.lower() in ('database', 'full'):
                self.logger.info("\n" + "="*70)
                self.logger.info("EXECUTING DATABASE DEPLOYMENT")
                self.logger.info("="*70)
                
                db_config = self.env.get_db_config(profile=environment)
                db_deployer = DBDeployer(db_config, migrations_dir=str(self.project_root / "database" / "migrations"))
                db_result = db_deployer.deploy(plan, auto_apply=auto_apply, dry_run=dry_run)
                
                self.logger.info(f"\nDB Result: {'✓ SUCCESS' if db_result['success'] else '✗ FAILED'}")
            
            # Update deployment marker
            if not dry_run:
                self.change_analyzer.update_deploy_marker()
            
            # Save plan
            self.planner.save_plan(plan, output_dir=str(self.log_dir))
            
            self.logger.info(f"\n{'#'*70}")
            self.logger.info(f"DEPLOYMENT COMPLETED")
            self.logger.info(f"{'#'*70}\n")
            self.logger.info(f"Log file: {self.log_file}\n")
            
            return 0
        
        except Exception as e:
            self.logger.error(f"\n❌ Deployment error: {e}", exc_info=True)
            return 1
    
    def preview(self, mode: str, environment: str, detect_mode: str = "git"):
        """Show deployment preview without executing"""
        self.logger.info(f"\nGenerating preview for {mode} deployment to {environment}...")
        
        try:
            changed_files = self.change_analyzer.analyze_changes(mode=detect_mode)
            
            if not changed_files:
                print("\nNo changes detected.")
                return 0
            
            plan = self.planner.plan_deployment(changed_files, mode=mode, environment=environment)
            preview = self.planner.preview(plan)
            print(preview)
            
            return 0
        
        except Exception as e:
            self.logger.error(f"Preview failed: {e}")
            return 1
    
    def status(self, environment: str):
        """Show deployment status"""
        self.logger.info(f"\nDeployment Status for {environment}:")
        self.logger.info(f"  Environment: {environment}")
        self.logger.info(f"  Profile: {self.env.profile}")
        
        ftp_config = self.env.get_ftp_config(profile=environment)
        self.logger.info(f"  FTP Host: {ftp_config['host']}")
        
        db_config = self.env.get_db_config(profile=environment)
        self.logger.info(f"  DB Host: {db_config['host']}")
        
        return 0


def main():
    """Main entry point"""
    parser = argparse.ArgumentParser(
        description="Asian Wok & Grill Deployer",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python deployer.py deploy --mode=ftp --env=LIVE
  python deployer.py deploy --mode=database --env=LIVE --auto-apply
  python deployer.py deploy --mode=full --env=LOCAL --dry-run
  python deployer.py preview --mode=ftp --env=LIVE
  python deployer.py status --env=LIVE
        """
    )
    
    subparsers = parser.add_subparsers(dest='command', help='Command to execute')
    
    # Deploy command
    deploy_parser = subparsers.add_parser('deploy', help='Execute deployment')
    deploy_parser.add_argument('--mode', choices=['ftp', 'database', 'backend', 'full'], 
                               default='full', help='Deployment mode')
    deploy_parser.add_argument('--env', choices=['LIVE', 'LOCAL'], default='LOCAL',
                               help='Target environment')
    deploy_parser.add_argument('--detect', choices=['git', 'timestamp'], default='git',
                               help='Change detection method')
    deploy_parser.add_argument('--auto-apply', action='store_true',
                               help='Auto-apply migrations without confirmation')
    deploy_parser.add_argument('--dry-run', action='store_true',
                               help='Preview only, do not execute')
    
    # Preview command
    preview_parser = subparsers.add_parser('preview', help='Preview deployment')
    preview_parser.add_argument('--mode', choices=['ftp', 'database', 'backend', 'full'],
                                default='full', help='Deployment mode')
    preview_parser.add_argument('--env', choices=['LIVE', 'LOCAL'], default='LOCAL',
                                help='Target environment')
    preview_parser.add_argument('--detect', choices=['git', 'timestamp'], default='git',
                                help='Change detection method')
    
    # Status command
    status_parser = subparsers.add_parser('status', help='Show deployment status')
    status_parser.add_argument('--env', choices=['LIVE', 'LOCAL'], default='LOCAL',
                               help='Target environment')
    
    args = parser.parse_args()
    
    if not args.command:
        parser.print_help()
        return 1
    
    # Create deployer instance
    deployer = Deployer(project_root=str(Path.cwd()))
    
    # Execute command
    try:
        if args.command == 'deploy':
            exit_code = deployer.deploy(
                mode=args.mode,
                environment=args.env,
                detect_mode=args.detect,
                auto_apply=args.auto_apply,
                dry_run=args.dry_run
            )
        elif args.command == 'preview':
            exit_code = deployer.preview(
                mode=args.mode,
                environment=args.env,
                detect_mode=args.detect
            )
        elif args.command == 'status':
            exit_code = deployer.status(environment=args.env)
        else:
            exit_code = 1
        
        # Cleanup old logs
        cleanup_old_logs(log_dir=str(deployer.log_dir), keep_count=10)
        
        return exit_code
    
    except KeyboardInterrupt:
        deployer.logger.info("\n⚠️  Deployment interrupted by user")
        return 2
    except Exception as e:
        deployer.logger.error(f"Fatal error: {e}", exc_info=True)
        return 1


if __name__ == '__main__':
    sys.exit(main())
