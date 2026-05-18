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
$backendRoot = Normalize-RemotePath (Get-ProfileValue -envMap $envMap -key 'FTP_REMOTE_PATH' -profile $profile)
$rootDeployTargets = @('/backend')

if ([string]::IsNullOrWhiteSpace($ftpHostValue) -or [string]::IsNullOrWhiteSpace($ftpUser) -or [string]::IsNullOrWhiteSpace($ftpPass) -or [string]::IsNullOrWhiteSpace($backendRoot)) {
  throw 'FTP configuration is incomplete in .env.'
}

$ftpHost = if ($ftpHostValue.StartsWith('ftp://') -or $ftpHostValue.StartsWith('sftp://')) { $ftpHostValue.TrimEnd('/') } else { "ftp://$($ftpHostValue.TrimEnd('/'))" }
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
      $req = New-FtpRequest -uri "$ftpHost/$curr" -method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
      $resp = $req.GetResponse()
      $resp.Close()
    } catch {
    }
  }
}

$deployItems = New-Object System.Collections.Generic.List[object]
foreach ($deployRoot in $rootDeployTargets) {
  $normalizedDeployRoot = Normalize-RemotePath $deployRoot
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'app'); RemoteRoot = "$normalizedDeployRoot/app"; Type = 'dir' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'bootstrap'); RemoteRoot = "$normalizedDeployRoot/bootstrap"; Type = 'dir' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'database'); RemoteRoot = "$normalizedDeployRoot/database"; Type = 'dir' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'storage'); RemoteRoot = "$normalizedDeployRoot/storage"; Type = 'dir' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot '.env'); RemoteRoot = "$normalizedDeployRoot/.env"; Type = 'file' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot '.htaccess'); RemoteRoot = "$normalizedDeployRoot/.htaccess"; Type = 'file' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'index.php'); RemoteRoot = "$normalizedDeployRoot/index.php"; Type = 'file' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'migrate.php'); RemoteRoot = "$normalizedDeployRoot/migrate.php"; Type = 'file' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'ops_probe.php'); RemoteRoot = "$normalizedDeployRoot/ops_probe.php"; Type = 'file' })
  $deployItems.Add(@{ LocalPath = (Join-Path $repoRoot 'router.php'); RemoteRoot = "$normalizedDeployRoot/router.php"; Type = 'file' })
}

$files = New-Object System.Collections.Generic.List[object]
foreach ($item in $deployItems) {
  if (-not (Test-Path $item.LocalPath)) { continue }

  if ($item.Type -eq 'file') {
    $files.Add([pscustomobject]@{ LocalFile = (Resolve-Path $item.LocalPath).Path; RemoteFile = $item.RemoteRoot })
    continue
  }

  $root = (Resolve-Path $item.LocalPath).Path
  Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
    $relative = $_.FullName.Substring($root.Length).TrimStart('\\').Replace('\\', '/')
    $files.Add([pscustomobject]@{ LocalFile = $_.FullName; RemoteFile = ($item.RemoteRoot.TrimEnd('/') + '/' + $relative) })
  }
}

$ok = 0
$fail = 0
foreach ($file in $files) {
  $remotePath = Normalize-RemotePath $file.RemoteFile
  $remoteDir = Split-Path -Path $remotePath -Parent
  New-RemoteDir -remoteDir $remoteDir

  try {
    $upload = New-FtpRequest -uri ($ftpHost + $remotePath) -method ([System.Net.WebRequestMethods+Ftp]::UploadFile)
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
}

Write-Output ("BACKEND_UPLOAD_DONE total={0} ok={1} fail={2}" -f $files.Count, $ok, $fail)
