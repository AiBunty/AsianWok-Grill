"""
Database Client - Handle MySQL connections, backups, and migrations
"""

from typing import Optional, Dict, List, Tuple
import mysql.connector
from mysql.connector import Error, CMySQLConnection
from pathlib import Path
import gzip
import subprocess
from datetime import datetime
from utils.logger import get_logger

logger = get_logger(__name__)


class DBClient:
    """MySQL database client with backup and restore capabilities"""
    
    def __init__(self, host: str, port: int, database: str, user: str, password: str,
                 timeout: int = 30):
        """
        Initialize database client
        
        Args:
            host: Database hostname
            port: Database port
            database: Database name
            user: Database user
            password: Database password
            timeout: Connection timeout
        """
        self.host = host
        self.port = port
        self.database = database
        self.user = user
        self.password = password
        self.timeout = timeout
        
        self.connection: Optional[CMySQLConnection] = None
        self.is_connected = False
    
    def connect(self) -> bool:
        """Connect to database"""
        try:
            logger.info(f"Connecting to database {self.user}@{self.host}:{self.port}/{self.database}...")
            
            self.connection = mysql.connector.connect(
                host=self.host,
                port=self.port,
                user=self.user,
                password=self.password,
                database=self.database,
                connection_timeout=self.timeout,
                autocommit=False  # Use transactions
            )
            
            if self.connection.is_connected():
                logger.info("✓ Database connection successful")
                self.is_connected = True
                return True
        
        except Error as e:
            logger.error(f"✗ Database connection failed: {e}")
            self.is_connected = False
            return False
    
    def disconnect(self):
        """Disconnect from database"""
        try:
            if self.connection and self.is_connected:
                self.connection.close()
                logger.info("Database disconnected")
        except Error as e:
            logger.warning(f"Error during disconnect: {e}")
        finally:
            self.is_connected = False
    
    def execute(self, query: str, params: Optional[tuple] = None) -> tuple:
        """
        Execute a query
        
        Returns:
            (success, result) where result is rowcount or fetched rows
        """
        if not self.is_connected:
            return False, "Not connected"
        
        try:
            cursor = self.connection.cursor()
            cursor.execute(query, params or ())
            
            if query.strip().upper().startswith('SELECT'):
                result = cursor.fetchall()
            else:
                result = cursor.rowcount
                self.connection.commit()
            
            cursor.close()
            return True, result
        
        except Error as e:
            logger.error(f"Query execution failed: {e}")
            self.connection.rollback()
            return False, str(e)
    
    def backup(self, backup_path: str, compress: bool = True) -> Tuple[bool, str]:
        """
        Backup database using mysqldump
        
        Args:
            backup_path: Path to save backup
            compress: If True, gzip the backup
        
        Returns:
            (success, message)
        """
        backup_dir = Path(backup_path).parent
        backup_dir.mkdir(parents=True, exist_ok=True)
        
        try:
            logger.info(f"Backing up database to {backup_path}...")
            
            # Use mysqldump command (must be available on system)
            cmd = [
                'mysqldump',
                f'--host={self.host}',
                f'--port={self.port}',
                f'--user={self.user}',
                f'--password={self.password}',
                self.database
            ]
            
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
            
            if result.returncode != 0:
                error = result.stderr or "Unknown error"
                logger.error(f"mysqldump failed: {error}")
                return False, error
            
            # Save backup
            backup_file = backup_path if not compress else backup_path + '.gz'
            
            if compress:
                with gzip.open(backup_file, 'wt', encoding='utf-8') as f:
                    f.write(result.stdout)
            else:
                with open(backup_file, 'w', encoding='utf-8') as f:
                    f.write(result.stdout)
            
            size_mb = Path(backup_file).stat().st_size / (1024 * 1024)
            logger.info(f"✓ Backup created: {backup_file} ({size_mb:.2f} MB)")
            
            return True, backup_file
        
        except subprocess.TimeoutExpired:
            error = "Backup timeout (>300s)"
            logger.error(error)
            return False, error
        except Exception as e:
            logger.error(f"Backup failed: {e}")
            return False, str(e)
    
    def restore(self, backup_path: str) -> Tuple[bool, str]:
        """
        Restore database from backup
        
        Args:
            backup_path: Path to backup file
        
        Returns:
            (success, message)
        """
        try:
            logger.warning(f"Restoring database from {backup_path}...")
            
            # Check if file exists
            if not Path(backup_path).exists():
                return False, f"Backup file not found: {backup_path}"
            
            # Handle compressed backups
            if backup_path.endswith('.gz'):
                import gzip
                with gzip.open(backup_path, 'rt', encoding='utf-8') as f:
                    sql_content = f.read()
            else:
                with open(backup_path, 'r', encoding='utf-8') as f:
                    sql_content = f.read()
            
            # Restore by executing SQL statements
            cursor = self.connection.cursor()
            
            for statement in sql_content.split(';'):
                stmt = statement.strip()
                if stmt:
                    cursor.execute(stmt)
            
            self.connection.commit()
            cursor.close()
            
            logger.info("✓ Database restored successfully")
            return True, "Restore completed"
        
        except Exception as e:
            logger.error(f"Restore failed: {e}")
            self.connection.rollback()
            return False, str(e)
    
    def get_applied_migrations(self) -> List[str]:
        """Get list of applied migrations from database"""
        query = """
        SELECT migration FROM migrations
        ORDER BY applied_at ASC
        """
        
        success, result = self.execute(query)
        
        if not success:
            logger.warning(f"Failed to get migrations: {result}")
            return []
        
        return [row[0] for row in result] if result else []
    
    def record_migration(self, migration_name: str) -> bool:
        """Record migration as applied"""
        query = """
        INSERT INTO migrations (migration, applied_at) VALUES (%s, NOW())
        """
        
        success, result = self.execute(query, (migration_name,))
        return success
    
    def execute_migration(self, migration_sql: str) -> Tuple[bool, str]:
        """Execute a migration SQL script"""
        try:
            logger.debug("Executing migration...")
            
            cursor = self.connection.cursor()
            
            # Split by ; and execute each statement
            for statement in migration_sql.split(';'):
                stmt = statement.strip()
                if stmt:
                    cursor.execute(stmt)
            
            self.connection.commit()
            cursor.close()
            
            logger.info("✓ Migration executed successfully")
            return True, "Executed"
        
        except Error as e:
            logger.error(f"Migration execution failed: {e}")
            self.connection.rollback()
            return False, str(e)
    
    def __enter__(self):
        """Context manager entry"""
        self.connect()
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb):
        """Context manager exit"""
        self.disconnect()
