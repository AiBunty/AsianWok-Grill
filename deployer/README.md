# Asian Wok & Grill Deployer

Professional Python-based deployment tool for separate FTP and database deployments with change detection, dry-run preview, and comprehensive logging.

## Features

✅ **Dual Deployment Modes**
- FTP deployment (frontend/backend files)
- Database deployment (migrations)
- Backend code deployment
- Full deployment (all targets)

✅ **Smart Change Detection**
- Git-based (detects staged/unstaged changes)
- Timestamp-based (files modified since last deploy)
- Ignore patterns (.gitignore, .deployignore, hardcoded)

✅ **Pre-flight Validation**
- Environment configuration checks
- FTP connectivity test
- Database connectivity test
- Disk space verification
- Git status check

✅ **Database Safety**
- Auto-backup before migrations
- Per-migration confirmations
- Transaction rollback on error
- Gzip compression for backups

✅ **Parallel Processing**
- Concurrent FTP uploads (5 threads by default)
- Configurable retry logic with exponential backoff
- Speed estimation and progress tracking

✅ **Comprehensive Logging**
- Colorized console output (INFO=green, WARN=yellow, ERROR=red)
- Separate log files for each deployment (FTP, DB, combined)
- Dry-run preview without executing
- Plan saved as JSON for audit trail

## Installation

1. **Navigate to deployer directory:**
   ```bash
   cd deployer
   ```

2. **Install Python dependencies:**
   ```bash
   pip install -r requirements.txt
   ```

3. **Verify configuration:**
   - `.env` file with FTP and DB credentials (at project root)
   - `deployer_config.yaml` with deployment settings (in deployer dir)
   - `.deployignore` with file ignore patterns (in deployer dir)

## Quick Start

### Preview deployment
```bash
python deployer.py preview --mode=ftp --env=LIVE
```

### Deploy with dry-run
```bash
python deployer.py deploy --mode=full --env=LOCAL --dry-run
```

### Deploy to LIVE (FTP only)
```bash
python deployer.py deploy --mode=ftp --env=LIVE
```

### Deploy database migrations
```bash
python deployer.py deploy --mode=database --env=LIVE --auto-apply
```

### Deploy everything
```bash
python deployer.py deploy --mode=full --env=LIVE
```

### Check deployment status
```bash
python deployer.py status --env=LIVE
```

## Commands & Options

### `deploy` - Execute deployment

**Mode:**
- `ftp` - Deploy frontend/backend files via FTP
- `database` - Run pending migrations
- `backend` - Deploy PHP backend code
- `full` - Deploy everything (FTP + database)

**Environment:**
- `LIVE` - Production (uses FTP_HOST_LIVE, DB_USER_LIVE, etc.)
- `LOCAL` - Development (uses FTP_HOST_LOCAL, DB_USER_LOCAL, etc.)

**Detect:**
- `git` - Use `git status` to find changes (default)
- `timestamp` - Compare file modification times vs. last deploy

**Flags:**
- `--auto-apply` - Skip confirmation prompts for migrations
- `--dry-run` - Preview only, don't execute

**Examples:**
```bash
# Deploy changed frontend files to LIVE
python deployer.py deploy --mode=ftp --env=LIVE

# Deploy pending migrations to LOCAL with auto-apply
python deployer.py deploy --mode=database --env=LOCAL --auto-apply

# Preview full deployment to LIVE
python deployer.py deploy --mode=full --env=LIVE --dry-run
```

### `preview` - Show deployment plan

Shows files that would be deployed without executing.

**Flags:**
- `--mode` - Deployment mode (default: full)
- `--env` - Environment (default: LOCAL)
- `--detect` - Change detection method (default: git)

**Example:**
```bash
python deployer.py preview --mode=ftp --env=LIVE
```

### `status` - Show configuration status

Displays current deployment configuration for an environment.

**Example:**
```bash
python deployer.py status --env=LIVE
```

## Configuration Files

### `.env` (Project Root)
Contains FTP and database credentials with profile variants:

```
# Current profile
NK_ENV_PROFILE=LIVE

# LIVE profile
FTP_HOST_LIVE=ftp.theboxerp.com
FTP_USER_LIVE=admin@asianwokandgrill.in
FTP_PASS_LIVE=password
FTP_PORT_LIVE=21

DB_HOST_LIVE=mysql.example.com
DB_PORT_LIVE=3306
DB_NAME_LIVE=database_name
DB_USER_LIVE=db_user
DB_PASS_LIVE=db_pass

# LOCAL profile
FTP_HOST_LOCAL=localhost
FTP_USER_LOCAL=local_user
FTP_PASS_LOCAL=local_pass

DB_HOST_LOCAL=127.0.0.1
DB_PORT_LOCAL=3306
DB_NAME_LOCAL=local_db
DB_USER_LOCAL=local_user
DB_PASS_LOCAL=local_pass
```

### `deployer_config.yaml` (Deployer Directory)
Controls deployment behavior:

```yaml
ftp:
  timeout: 60              # Connection timeout
  retries: 3              # Retry attempts
  parallel_workers: 5     # Concurrent uploads
  
database:
  timeout: 30
  backup_enabled: true
  backup_compress: true
  
deployment:
  log_level: "INFO"       # DEBUG, INFO, WARNING, ERROR
  interactive_migrations: true
```

### `.deployignore` (Deployer Directory)
Files/patterns to never deploy (similar to .gitignore):

```
# Example patterns
deployer/
*.log
node_modules/
.git/
*.pyc
```

## Logs & Outputs

All deployment logs are stored in `deployer/logs/`:

```
deployer_20260610_150458.log     # Combined log
plan_20260610_150458.json        # Deployment plan
backups/
  pre_migration_20260610_150458.sql.gz  # DB backups
  
.last_deploy_marker              # Timestamp of last deployment
```

## Workflow

### Typical FTP Deployment

1. **Modify files** locally (HTML, CSS, JS, PHP)
   ```bash
   # Edit files...
   git add .
   ```

2. **Preview changes**
   ```bash
   python deployer.py preview --mode=ftp --env=LIVE
   ```

3. **Deploy**
   ```bash
   python deployer.py deploy --mode=ftp --env=LIVE
   ```

### Typical Database Migration Deployment

1. **Create migration file** in `database/migrations/`
   ```
   040_add_new_column.sql
   ```

2. **Preview migrations**
   ```bash
   python deployer.py preview --mode=database --env=LIVE
   ```

3. **Apply migrations**
   ```bash
   python deployer.py deploy --mode=database --env=LIVE
   ```

### Full Deployment (Code + Database)

1. **Make changes** to PHP code and create migrations
2. **Preview all changes**
   ```bash
   python deployer.py deploy --mode=full --env=LIVE --dry-run
   ```
3. **Deploy everything**
   ```bash
   python deployer.py deploy --mode=full --env=LIVE
   ```

## Troubleshooting

### "FTP connection failed"
- Check `FTP_HOST_LIVE` and `FTP_USER_LIVE` in `.env`
- Verify firewall allows port 21 (or custom FTP_PORT)
- Test connectivity: `ping ftp.theboxerp.com`

### "Database connection failed"
- Check `DB_HOST_LIVE`, `DB_USER_LIVE`, `DB_PASS_LIVE` in `.env`
- Verify database is running: `telnet db.host 3306`
- Check user permissions in MySQL

### "No changes detected"
- Run `git status` to verify changes are staged
- Use `--detect=timestamp` to compare modification times
- Check `.deployignore` patterns (may be excluding files)

### Migration "already applied"
- Verify migration name in `database/migrations/` is new
- Check `migrations` table in database for duplicates

### Slow uploads
- Increase `parallel_workers` in `deployer_config.yaml` (5-10)
- Network speed depends on hosting provider
- Large files upload slower (images already skipped on changes)

## Development & Testing

### Run Phase 1 & 2 Tests
```bash
python test_phase1_phase2.py
```

### Create New Migration
```bash
# Create migration file
cat > database/migrations/040_example.sql << EOF
ALTER TABLE table_name ADD COLUMN new_column VARCHAR(255);
EOF

# Deploy
python deployer.py deploy --mode=database --env=LOCAL
```

### Debug Logging
Set log level to DEBUG in `deployer_config.yaml`:
```yaml
deployment:
  log_level: "DEBUG"
```

## Architecture

```
deployer/
├── deployer.py              # Main CLI
├── core/
│   ├── env_parser.py        # Parse .env with profiles
│   ├── ignore_manager.py    # Handle ignore patterns
│   ├── change_analyzer.py   # Detect changes (git/timestamp)
│   └── deployment_planner.py # Plan deployment
├── ftp_module/
│   ├── ftp_client.py        # FTP connection & operations
│   └── ftp_deployer.py      # Execute FTP deployment
├── db_module/
│   ├── db_client.py         # MySQL operations
│   ├── migration_analyzer.py # Detect & execute migrations
│   └── db_deployer.py       # Orchestrate DB deployment
├── utils/
│   ├── logger.py            # Colored logging
│   └── validation.py        # Pre-flight checks
└── logs/
    ├── deployer_*.log       # Deployment logs
    ├── plan_*.json          # Deployment plans
    └── backups/             # Database backups
```

## Exit Codes

- `0` - Success
- `1` - Error/failure
- `2` - User cancelled

## Requirements

- Python 3.7+
- PyYAML
- mysql-connector-python
- Git (for git-based change detection)
- mysqldump (for backups, optional)

## Support

For issues or questions:
1. Check logs in `deployer/logs/`
2. Run with `--detect=timestamp` if Git issues
3. Run `python deployer.py status --env=LIVE` to verify configuration
4. Review this README and `deployer_config.yaml`

---

**Version:** 1.0  
**Last Updated:** 2026-06-10
