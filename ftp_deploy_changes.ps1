# Targeted deploy: uploads only source/text files, skips binary assets (images, fonts, enc files)
# Use this for deploying code changes without re-uploading slow large files.

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
  throw 'FTP_REMOTE_PATH must point to the backend directory, for example /public_html/backend.'
}

$backendRoot = Normalize-RemotePath $backendRemoteRoot
$siteRoot = Normalize-RemotePath $publicRemoteRoot

# Extensions to deploy (source/text files only — skip images, fonts, binary)
$includeExtensions = @('.html', '.htm', '.css', '.js', '.php', '.sql', '.xml', '.txt', '.json', '.md', '.htaccess', '.env', '.svg', '.ico')

$deployItems = @(
  @{ LocalPath = (Join-Path $repoRoot 'asianwokandgrill.in'); RemoteRoot = $siteRoot; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'app'); RemoteRoot = "$backendRoot/app"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'bootstrap'); RemoteRoot = "$backendRoot/bootstrap"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'database'); RemoteRoot = "$backendRoot/database"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot 'storage'); RemoteRoot = "$backendRoot/storage"; Type = 'dir' },
  @{ LocalPath = (Join-Path $repoRoot '.env'); RemoteRoot = "$backendRoot/.env"; Type = 'file' },
  @{ LocalPath = (Join-Path $repoRoot 'index.php'); RemoteRoot = "$backendRoot/index.php"; Type = 'file' },
  @{ LocalPath = (Join-Path $repoRoot 'migrate.php'); RemoteRoot = "$backendRoot/migrate.php"; Type = 'file' }
)

$files = New-Object System.Collections.Generic.List[object]

foreach ($item in $deployItems) {
  if (-not (Test-Path $item.LocalPath)) { continue }

  if ($item.Type -eq 'file') {
    $ext = [System.IO.Path]::GetExtension($item.LocalPath).ToLower()
    $name = [System.IO.Path]::GetFileName($item.LocalPath)
    if ($includeExtensions -contains $ext -or $includeExtensions -contains ".$name") {
      $files.Add([pscustomobject]@{ LocalFile = (Resolve-Path $item.LocalPath).Path; RemoteFile = $item.RemoteRoot })
    }
    continue
  }

  $root = (Resolve-Path $item.LocalPath).Path
  Get-ChildItem -Path $root -Recurse -File | Where-Object {
    $ext = $_.Extension.ToLower()
    $includeExtensions -contains $ext -or $_.Name -eq '.htaccess'
  } | ForEach-Object {
    $relative = $_.FullName.Substring($root.Length).TrimStart('\\').Replace('\\', '/')
    $files.Add([pscustomobject]@{ LocalFile = $_.FullName; RemoteFile = ($item.RemoteRoot.TrimEnd('/') + '/' + $relative) })
  }
}

$total = $files.Count
$ok = 0
$fail = 0

Write-Host ("Starting targeted upload of {0} source files to {1}" -f $total, $ftpHost) -ForegroundColor Cyan
Write-Host ("(Binary files like images/fonts/enc are skipped)") -ForegroundColor DarkGray

$curlCreds = "${ftpUser}:${ftpPass}"

for ($i = 0; $i -lt $total; $i++) {
  $file = $files[$i]
  $remotePath = Normalize-RemotePath $file.RemoteFile

  try {
    # URL-encode path segments to handle spaces and special chars in filenames
    $encodedSegments = $remotePath.TrimStart('/').Split('/') | ForEach-Object { [Uri]::EscapeDataString($_) }
    $encodedUri = "$ftpHost/" + ($encodedSegments -join '/')

    $curlOut = curl.exe --silent --show-error --connect-timeout 30 --max-time 120 `
      --ftp-create-dirs -u $curlCreds -T $file.LocalFile $encodedUri 2>&1
    if ($LASTEXITCODE -ne 0) {
      throw ($curlOut -join ' ')
    }
    $ok++
  } catch {
    $fail++
    Write-Host ("FAILED: {0} :: {1}" -f $remotePath, $_.Exception.Message) -ForegroundColor Red
  }

  if (((($i + 1) % 10) -eq 0 -or ($i + 1) -eq $total)) {
    Write-Host ("Progress: {0}/{1} (ok={2}, fail={3})" -f ($i + 1), $total, $ok, $fail) -ForegroundColor Yellow
  }
}

Write-Host ""
if ($fail -eq 0) {
  Write-Host ("DONE: All {0} files uploaded successfully!" -f $ok) -ForegroundColor Green
} else {
  Write-Host ("DONE: total={0}, ok={1}, FAILED={2}" -f $total, $ok, $fail) -ForegroundColor Yellow
}
