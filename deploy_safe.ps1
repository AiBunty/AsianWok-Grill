$ErrorActionPreference = 'Stop'

function Get-EnvMap([string]$path) {
  $map = @{}
  foreach ($line in Get-Content -Path $path) {
    if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
    $parts = $line.Split('=', 2)
    if ($parts.Count -ne 2) { continue }
    $map[$parts[0].Trim()] = $parts[1].Trim()
  }
  return $map
}

function Get-ProfileValue($envMap, [string]$key, [string]$profile) {
  $profileKey = if ([string]::IsNullOrWhiteSpace($profile)) { '' } else { "${key}_$($profile.ToUpperInvariant())" }
  if ($profileKey -and $envMap.ContainsKey($profileKey)) { return $envMap[$profileKey] }
  if ($envMap.ContainsKey($key)) { return $envMap[$key] }
  return ''
}

function Normalize-RemotePath([string]$path) {
  $trimmed = ($path -replace '\\', '/').Trim()
  if ([string]::IsNullOrWhiteSpace($trimmed)) { return '/' }
  if (-not $trimmed.StartsWith('/')) { $trimmed = '/' + $trimmed }
  return $trimmed.TrimEnd('/')
}

$repoRoot = (Resolve-Path '.').Path
$envMap = Get-EnvMap -path (Join-Path $repoRoot '.env')
$profile = if ($envMap.ContainsKey('NK_ENV_PROFILE')) { $envMap['NK_ENV_PROFILE'] } else { '' }

$ftpHostValue = Get-ProfileValue -envMap $envMap -key 'FTP_HOST' -profile $profile
$ftpUser = Get-ProfileValue -envMap $envMap -key 'FTP_USER' -profile $profile
$ftpPass = Get-ProfileValue -envMap $envMap -key 'FTP_PASS' -profile $profile
$backendRemoteRoot = Normalize-RemotePath (Get-ProfileValue -envMap $envMap -key 'FTP_REMOTE_PATH' -profile $profile)

if ([string]::IsNullOrWhiteSpace($ftpHostValue) -or [string]::IsNullOrWhiteSpace($ftpUser) -or [string]::IsNullOrWhiteSpace($ftpPass) -or [string]::IsNullOrWhiteSpace($backendRemoteRoot)) {
  throw 'FTP configuration is incomplete in .env.'
}

$ftpHost = if ($ftpHostValue.StartsWith('ftp://') -or $ftpHostValue.StartsWith('sftp://')) { $ftpHostValue.TrimEnd('/') } else { "ftp://$($ftpHostValue.TrimEnd('/'))" }
$publicRemoteRoot = Split-Path -Path $backendRemoteRoot -Parent
if ([string]::IsNullOrWhiteSpace($publicRemoteRoot)) {
  throw 'FTP_REMOTE_PATH must point to backend directory, for example /public_html/backend.'
}

$cred = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)

function New-FtpRequest([string]$uri, [string]$method) {
  $req = [System.Net.FtpWebRequest]::Create($uri)
  $req.Method = $method
  $req.Credentials = $cred
  $req.UseBinary = $true
  $req.UsePassive = $true
  $req.KeepAlive = $false
  return $req
}

function New-RemoteDir([string]$remoteDir) {
  $remoteDir = Normalize-RemotePath $remoteDir
  if ($remoteDir -eq '/') { return }

  $parts = $remoteDir.TrimStart('/') -split '/'
  $curr = ''
  foreach ($p in $parts) {
    if ([string]::IsNullOrWhiteSpace($p)) { continue }
    $curr = if ($curr) { "$curr/$p" } else { $p }

    try {
      $mkd = New-FtpRequest -uri "$ftpHost/$curr" -method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
      $resp = $mkd.GetResponse()
      $resp.Close()
    } catch {
    }
  }
}

$backendRoot = Normalize-RemotePath $backendRemoteRoot
$siteRoot = Normalize-RemotePath $publicRemoteRoot

$deployItems = @(
  @{ LocalPath = (Join-Path $repoRoot 'asianwokandgrill.in'); RemoteRoot = $siteRoot; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'app'); RemoteRoot = "$backendRoot/app"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'bootstrap'); RemoteRoot = "$backendRoot/bootstrap"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'config'); RemoteRoot = "$backendRoot/config"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'database'); RemoteRoot = "$backendRoot/database"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'storage'); RemoteRoot = "$backendRoot/storage"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot '.htaccess'); RemoteRoot = "$backendRoot/.htaccess"; Type = 'file' },
  @{ LocalPath = (Join-Path $repoRoot 'index.php'); RemoteRoot = "$backendRoot/index.php"; Type = 'file' },
  @{ LocalPath = (Join-Path $repoRoot 'migrate.php'); RemoteRoot = "$backendRoot/migrate.php"; Type = 'file' },
  @{ LocalPath = (Join-Path $repoRoot 'ops_probe.php'); RemoteRoot = "$backendRoot/ops_probe.php"; Type = 'file' },
  @{ LocalPath = (Join-Path $repoRoot 'router.php'); RemoteRoot = "$backendRoot/router.php"; Type = 'file' }
)

$files = New-Object System.Collections.Generic.List[object]

foreach ($item in $deployItems) {
  if (-not (Test-Path $item.LocalPath)) { continue }

  if ($item.Type -eq 'file') {
    $files.Add([pscustomobject]@{ LocalFile = (Resolve-Path $item.LocalPath).Path; RemoteFile = $item.RemoteRoot })
    continue
  }

  $root = (Resolve-Path $item.LocalPath).Path
  Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
    $full = $_.FullName
    if ($full -match '\\.git\\' -or $full -match '\\.vscode\\' -or $full -match '\\node_modules\\') { return }
    if ($_.Name -match '^tmp_') { return }
    if ($_.Extension -in '.md', '.log', '.zip') { return }

    $relative = $full.Substring($root.Length).TrimStart('\\').Replace('\\', '/')
    $files.Add([pscustomobject]@{ LocalFile = $full; RemoteFile = ($item.RemoteRoot.TrimEnd('/') + '/' + $relative) })
  }
}

$total = $files.Count
$ok = 0
$fail = 0

for ($i = 0; $i -lt $total; $i++) {
  $file = $files[$i]
  $remotePath = Normalize-RemotePath $file.RemoteFile
  $remoteDir = Split-Path -Path $remotePath -Parent

  New-RemoteDir -remoteDir $remoteDir

  $remoteUri = "$ftpHost$remotePath"

  try {
    $upload = New-FtpRequest -uri $remoteUri -method ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [System.IO.File]::ReadAllBytes($file.LocalFile)
    $upload.ContentLength = $bytes.Length

    $stream = $upload.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()

    $resp = $upload.GetResponse()
    $resp.Close()

    $ok++
  } catch {
    $fail++
    Write-Output ("FAILED: {0} :: {1}" -f $remotePath, $_.Exception.Message)
  }

  if (((($i + 1) % 100) -eq 0 -or ($i + 1) -eq $total)) {
    Write-Output ("Progress: {0}/{1} uploaded (ok={2}, fail={3})" -f ($i + 1), $total, $ok, $fail)
  }
}

Write-Output ("SAFE_DEPLOY_DONE total={0}, ok={1}, fail={2}" -f $total, $ok, $fail)
