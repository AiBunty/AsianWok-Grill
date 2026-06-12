"""
FTP Deployer - Execute FTP deployment with logging and batch processing
"""

import threading
import queue
from pathlib import Path
from typing import List, Dict, Optional
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
from ftp_module.ftp_client import FTPClient, FTPUploadResult
from core.deployment_planner import DeploymentTarget, DeploymentPlan
from utils.logger import get_logger

logger = get_logger(__name__)


class FTPDeployer:
    """Handle FTP deployment with parallel uploads and logging"""
    
    def __init__(self, ftp_config: Dict[str, str], max_workers: int = 5, 
                 timeout: int = 60, retries: int = 3):
        """
        Initialize FTP deployer
        
        Args:
            ftp_config: FTP configuration dict (host, user, password, remote_path)
            max_workers: Number of parallel upload threads
            timeout: FTP connection timeout
            retries: Number of retry attempts
        """
        self.ftp_config = ftp_config
        self.max_workers = max_workers
        self.timeout = timeout
        self.retries = retries
        
        self.results: List[FTPUploadResult] = []
        self.errors: List[str] = []
    
    def deploy(self, plan: DeploymentPlan, dry_run: bool = False) -> Dict:
        """
        Execute FTP deployment based on plan
        
        Args:
            plan: DeploymentPlan object
            dry_run: If True, don't actually upload (preview only)
        
        Returns:
            Deployment result dict with statistics
        """
        logger.info(f"\n{'='*70}")
        logger.info(f"FTP DEPLOYMENT | Mode: {plan.mode.upper()} | Env: {plan.environment}")
        logger.info(f"{'='*70}\n")
        
        # Filter FTP targets only
        ftp_targets = [t for t in plan.targets if t.type == 'ftp']
        
        if not ftp_targets:
            logger.info("No FTP targets in deployment plan")
            return self._summary([], dry_run)
        
        # Collect all files to upload
        all_files = []
        for target in ftp_targets:
            for cf in target.files:
                all_files.append((cf, target))
        
        logger.info(f"FTP Deployment Plan:")
        logger.info(f"  Files to upload: {len(all_files)}")
        logger.info(f"  Total size: {sum(f[0].size for f in all_files) / (1024*1024):.2f} MB")
        logger.info(f"  Parallel workers: {self.max_workers}")
        
        if dry_run:
            logger.warning("\n⚠️  DRY-RUN MODE - Files will NOT be uploaded\n")
            for cf, target in all_files[:10]:
                logger.info(f"  [DRY] Would upload: {cf.relative_path} → {target.remote_path}")
            if len(all_files) > 10:
                logger.info(f"  [DRY] ... and {len(all_files) - 10} more files")
            return self._summary([], dry_run=True)
        
        # Show preview and ask for confirmation
        logger.info("\nReady to upload. Proceeding...\n")
        
        # Execute uploads
        self.results = []
        self._upload_files_parallel(all_files)
        
        return self._summary(self.results, dry_run)
    
    def _upload_files_parallel(self, files_to_upload: List[tuple]):
        """Upload files in parallel batches"""
        
        # Connect to FTP
        try:
            ftp = FTPClient(
                host=self.ftp_config['host'],
                user=self.ftp_config['user'],
                password=self.ftp_config['password'],
                port=int(self.ftp_config.get('port', 21)),
                timeout=self.timeout,
                retries=self.retries
            )
            
            if not ftp.connect():
                self.errors.append("Failed to connect to FTP server")
                return
            
            try:
                # Upload files using ThreadPoolExecutor
                with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
                    futures = {}
                    
                    for cf, target in files_to_upload:
                        remote_file = str(Path(target.remote_path) / Path(cf.relative_path).name)
                        future = executor.submit(self._upload_single, ftp, cf, remote_file)
                        futures[future] = (cf, target)
                    
                    # Collect results as they complete
                    for future in as_completed(futures):
                        try:
                            result = future.result()
                            self.results.append(result)
                            
                            if not result.success:
                                self.errors.append(result.error)
                        
                        except Exception as e:
                            error = f"Upload error: {e}"
                            self.errors.append(error)
                            logger.error(error)
            
            finally:
                ftp.disconnect()
        
        except Exception as e:
            error = f"FTP deployment error: {e}"
            self.errors.append(error)
            logger.error(error)
    
    def _upload_single(self, ftp: FTPClient, cf, remote_file: str) -> FTPUploadResult:
        """Upload a single file (called by thread)"""
        return ftp.upload_file(str(cf.local_path), remote_file)
    
    def _summary(self, results: List[FTPUploadResult], dry_run: bool = False) -> Dict:
        """Generate deployment summary"""
        
        successful = sum(1 for r in results if r.success)
        failed = sum(1 for r in results if not r.success)
        total_size = sum(r.size_bytes for r in results)
        total_time = sum(r.time_seconds for r in results)
        
        logger.info(f"\n{'='*70}")
        logger.info(f"FTP DEPLOYMENT SUMMARY")
        logger.info(f"{'='*70}\n")
        
        if dry_run:
            logger.info(f"DRY-RUN MODE - No files were uploaded")
        else:
            logger.info(f"✓ Successful: {successful}/{len(results)}")
            logger.info(f"✗ Failed: {failed}/{len(results)}")
            logger.info(f"Total size: {total_size / (1024*1024):.2f} MB")
            logger.info(f"Total time: {total_time:.2f} seconds")
            
            if failed > 0:
                logger.warning(f"\nFailed uploads:")
                for r in results:
                    if not r.success:
                        logger.warning(f"  • {r.file_path} - {r.error}")
            
            if successful > 0:
                logger.info(f"\nSuccessful uploads (first 5):")
                for r in results[:5]:
                    if r.success:
                        logger.info(f"  ✓ {r.file_path} ({r.size_bytes / 1024:.1f} KB, {r.time_seconds:.2f}s)")
                
                if len(results) > 5:
                    logger.info(f"  ... and {len(results) - 5} more")
        
        logger.info(f"\n{'='*70}\n")
        
        return {
            'success': failed == 0,
            'dry_run': dry_run,
            'total_files': len(results),
            'successful': successful,
            'failed': failed,
            'total_size_bytes': total_size,
            'total_time_seconds': total_time,
            'errors': self.errors,
            'results': results
        }
