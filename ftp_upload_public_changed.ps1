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

if ([string]::IsNullOrWhiteSpace($ftpHostValue) -or [string]::IsNullOrWhiteSpace($ftpUser) -or [string]::IsNullOrWhiteSpace($ftpPass) -or [string]::IsNullOrWhiteSpace($backendRoot)) {
  throw 'FTP configuration is incomplete in .env.'
}

$ftpHost = if ($ftpHostValue.StartsWith('ftp://') -or $ftpHostValue.StartsWith('sftp://')) { $ftpHostValue.TrimEnd('/') } else { "ftp://$($ftpHostValue.TrimEnd('/'))" }
$publicRoot = Normalize-RemotePath (Split-Path -Path $backendRoot -Parent)
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

$items = @(
  'asianwokandgrill.in/.htaccess',
  'asianwokandgrill.in/index.html',
  'asianwokandgrill.in/home.html',
  'asianwokandgrill.in/menu.html',
  'asianwokandgrill.in/namastemenu.html',
  'asianwokandgrill.in/namaste_chef.html',
  'asianwokandgrill.in/cocktail.html',
  'asianwokandgrill.in/lead-capture.html',
  'asianwokandgrill.in/assets/.htaccess',
  'asianwokandgrill.in/assets/js/menu-data.js',
  'asianwokandgrill.in/assets/js/menu-gate.js',
  'asianwokandgrill.in/js/runtime-config.js',
  'asianwokandgrill.in/js/lead-capture.js',
  'asianwokandgrill.in/js/menu-blocker.js',
  'asianwokandgrill.in/js/data/country-codes-all.js',
  'asianwokandgrill.in/js/admin-modules/base.js',
  'asianwokandgrill.in/js/admin-modules/api-client.js',
  'asianwokandgrill.in/js/admin-modules/dashboard.js',
  'asianwokandgrill.in/js/admin-modules/verification.js',
  'asianwokandgrill.in/js/admin-modules/events.js',
  'asianwokandgrill.in/js/admin-modules/cashier.js',
  'asianwokandgrill.in/js/admin-modules/cash-approvals.js',
  'asianwokandgrill.in/js/admin-modules/event-guests.js',
  'asianwokandgrill.in/js/admin-modules/event-entry-scanner.js',
  'asianwokandgrill.in/js/admin-modules/spin-offer-control.js',
  'asianwokandgrill.in/js/admin-modules/menu-editor.js',
  'asianwokandgrill.in/js/admin-modules/menu-editor-shell.js',
  'asianwokandgrill.in/js/admin-modules/menu-category-designer.js',
  'asianwokandgrill.in/js/admin-modules/menu-category-designer-shell.js',
  'asianwokandgrill.in/js/admin-modules/data-import.js',
  'asianwokandgrill.in/js/admin-modules/landing-routing.js',
  'asianwokandgrill.in/js/admin-modules/qr-code.js',
  'asianwokandgrill.in/js/admin-modules/crm-panel.js',
  'asianwokandgrill.in/js/admin-modules/crm-leads.js',
  'asianwokandgrill.in/js/admin-modules/menu-blocker-admin.js',
  'asianwokandgrill.in/js/admin-modules/settings.js',
  'asianwokandgrill.in/js/admin-modules/whatsapp-cloud.js',
  'asianwokandgrill.in/js/admin-modules/diagnostics.js',
  'asianwokandgrill.in/js/admin-modules/crm.js',
  'asianwokandgrill.in/js/admin-modules/users.js',
  'asianwokandgrill.in/js/admin-modules/menu-workspace.js',
  'asianwokandgrill.in/js/admin-modules/login.js',
  'asianwokandgrill.in/css/menu-blocker.css',
  'asianwokandgrill.in/css/admin-menu-blocker.css',
  'asianwokandgrill.in/admin/index.html',
  'asianwokandgrill.in/admin/login.html',
  'asianwokandgrill.in/admin/admin.css'
)

$ok = 0
$fail = 0
foreach ($item in $items) {
  if (-not (Test-Path $item)) {
    Write-Host "SKIP (not found): $item" -ForegroundColor Yellow
    continue
  }
  $local = (Resolve-Path $item).Path
  $remote = $publicRoot + '/' + $item.Substring('asianwokandgrill.in/'.Length).Replace('\\', '/')
  $remoteDir = Split-Path -Path $remote -Parent
  New-RemoteDir -remoteDir $remoteDir

  try {
    $upload = New-FtpRequest -uri ($ftpHost + $remote) -method ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [System.IO.File]::ReadAllBytes($local)
    $upload.ContentLength = $bytes.Length

    $stream = $upload.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()

    $resp = $upload.GetResponse()
    $resp.Close()
    $ok++
    Write-Output ('UPLOADED ' + $remote)
  } catch {
    $fail++
    Write-Output ('FAILED ' + $remote + ' :: ' + $_.Exception.Message)
  }
}

Write-Output ('PUBLIC_UPLOAD_DONE ok=' + $ok + ' fail=' + $fail)
