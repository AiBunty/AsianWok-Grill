#!/usr/bin/env python3
"""
Quick test script for Deployer Phase 1 & 2
Tests: env_parser, ignore_manager, change_analyzer, deployment_planner
"""

import sys
import os
from pathlib import Path

# Add deployer to path
deployer_dir = Path(__file__).parent
sys.path.insert(0, str(deployer_dir))

from core.env_parser import EnvParser
from core.ignore_manager import IgnoreManager
from core.change_analyzer import ChangeAnalyzer
from core.deployment_planner import DeploymentPlanner
from utils.logger import setup_logger, cleanup_old_logs
from utils.validation import DeploymentValidator

# Setup logging
logger_obj, log_file = setup_logger("deployer.test", log_dir=str(deployer_dir / "logs"), log_level="DEBUG")
logger = logger_obj

print("\n" + "="*70)
print("DEPLOYER PHASE 1 & 2 - QUICK TEST")
print("="*70 + "\n")

# Test 1: Environment Parser
print("🔍 TEST 1: Environment Parser")
print("-" * 70)
try:
    env = EnvParser(env_file_path=str(Path.cwd() / ".env"))
    logger.info(f"Environment loaded: {env}")
    
    ftp_config = env.get_ftp_config()
    db_config = env.get_db_config()
    
    logger.info(f"FTP Host: {ftp_config['host']}")
    logger.info(f"DB Host: {db_config['host']}")
    
    is_valid, errors = env.validate()
    if is_valid:
        print("✅ Environment validation PASSED\n")
    else:
        print(f"❌ Environment validation FAILED: {errors}\n")
        sys.exit(1)

except Exception as e:
    logger.error(f"❌ Test 1 failed: {e}\n")
    sys.exit(1)

# Test 2: Ignore Manager
print("🔍 TEST 2: Ignore Manager")
print("-" * 70)
try:
    ignore_mgr = IgnoreManager(
        project_root=str(Path.cwd()),
        gitignore_path=str(Path.cwd() / ".gitignore"),
        deployignore_path=str(deployer_dir / ".deployignore")
    )
    
    # Test some patterns
    test_paths = [
        "asianwokandgrill.in/menu.html",      # Should NOT ignore
        "app/Controllers/MenuController.php",  # Should NOT ignore
        "node_modules/package.json",          # Should ignore
        ".git/config",                        # Should ignore
        "deployer/logs/test.log",            # Should ignore
    ]
    
    print("\nPattern matching tests:")
    for path in test_paths:
        should_ignore = ignore_mgr.should_ignore(path)
        status = "❌ IGNORE" if should_ignore else "✅ DEPLOY"
        print(f"  {status} | {path}")
    
    print(f"\n✅ Ignore Manager loaded successfully\n")

except Exception as e:
    logger.error(f"❌ Test 2 failed: {e}\n")
    sys.exit(1)

# Test 3: Change Analyzer (Git mode)
print("🔍 TEST 3: Change Analyzer (Git Mode)")
print("-" * 70)
try:
    analyzer = ChangeAnalyzer(
        project_root=str(Path.cwd()),
        ignore_manager=ignore_mgr
    )
    
    try:
        changed_files = analyzer.analyze_git_changes()
        summary = analyzer.summary(changed_files)
        logger.info(summary)
        
        if changed_files:
            print(f"\nFound {len(changed_files)} changed files:")
            for cf in changed_files[:5]:
                print(f"  • {cf.relative_path} ({cf.size / 1024:.1f} KB) [{cf.file_type}]")
            if len(changed_files) > 5:
                print(f"  ... and {len(changed_files) - 5} more")
        
        print(f"\n✅ Change Analyzer (Git) completed successfully\n")
    
    except Exception as git_error:
        logger.warning(f"Git analysis not available: {git_error}")
        print("⚠️  Git not available (skipping git-based analysis)\n")

except Exception as e:
    logger.error(f"❌ Test 3 failed: {e}\n")

# Test 4: Deployment Planner
print("🔍 TEST 4: Deployment Planner")
print("-" * 70)
try:
    planner = DeploymentPlanner(project_root=str(Path.cwd()))
    
    # Use empty file list for demo (or real changes if available)
    from core.change_analyzer import ChangedFile
    demo_files = [
        ChangedFile(
            local_path=Path("asianwokandgrill.in/menu.html"),
            relative_path="asianwokandgrill.in/menu.html",
            file_type="M",
            size=50000
        ),
        ChangedFile(
            local_path=Path("app/Services/MenuService.php"),
            relative_path="app/Services/MenuService.php",
            file_type="M",
            size=15000
        ),
    ]
    
    plan = planner.plan_deployment(demo_files, mode="full", environment="LOCAL")
    preview = planner.preview(plan)
    print(preview)
    
    plan_file = planner.save_plan(plan, output_dir=str(deployer_dir / "logs"))
    print(f"✅ Deployment Plan saved to: {plan_file}\n")

except Exception as e:
    logger.error(f"❌ Test 4 failed: {e}\n")
    sys.exit(1)

# Test 5: Validator
print("🔍 TEST 5: Deployment Validator")
print("-" * 70)
try:
    validator = DeploymentValidator(env, project_root=str(Path.cwd()))
    is_valid, errors, warnings = validator.validate_all(check_git=False)
    
    if is_valid:
        print("✅ All validation checks PASSED\n")
    else:
        print(f"❌ Validation FAILED with {len(errors)} error(s)\n")

except Exception as e:
    logger.error(f"❌ Test 5 failed: {e}\n")

# Cleanup old logs
cleanup_old_logs(log_dir=str(deployer_dir / "logs"), keep_count=10)

print("="*70)
print("✅ ALL TESTS COMPLETED")
print("="*70)
print(f"\n📋 Full log available at: {log_file}\n")
