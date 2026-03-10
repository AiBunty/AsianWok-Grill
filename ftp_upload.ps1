$ErrorActionPreference = 'Stop'

$ftpHost = 'ftp://ftp.theboxerp.com'
$ftpUser = 'admin@asianwokandgrill.in'
$ftpPass = 'Zebra@789'
$localRoot = (Resolve-Path 'asianwokandgrill.in').Path

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

function Ensure-RemoteDir([string]$relativeDir) {
  if ([string]::IsNullOrWhiteSpace($relativeDir)) { return }
  $parts = $relativeDir -split '/'
  $curr = ''

  foreach ($p in $parts) {
    if ([string]::IsNullOrWhiteSpace($p)) { continue }
    $curr = if ($curr) { "$curr/$p" } else { $p }
    $uri = "$ftpHost/$curr"

    try {
      $mkd = New-FtpRequest -uri $uri -method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
      $resp = $mkd.GetResponse()
      $resp.Close()
    } catch {
      # Directory may already exist; ignore.
    }
  }
}

$files = Get-ChildItem -Path $localRoot -Recurse -File
$total = $files.Count
$ok = 0
$fail = 0

for ($i = 0; $i -lt $total; $i++) {
  $file = $files[$i]
  $rel = $file.FullName.Substring($localRoot.Length).TrimStart('\\').Replace('\\', '/')
  $relDir = [System.IO.Path]::GetDirectoryName($rel)
  if ($relDir) { $relDir = $relDir.Replace('\\', '/') }

  Ensure-RemoteDir -relativeDir $relDir

  $remoteUri = "$ftpHost/$rel"

  try {
    $upload = New-FtpRequest -uri $remoteUri -method ([System.Net.WebRequestMethods+Ftp]::UploadFile)
    $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
    $upload.ContentLength = $bytes.Length

    $stream = $upload.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()

    $resp = $upload.GetResponse()
    $resp.Close()

    $ok++
  } catch {
    $fail++
    Write-Output ("FAILED: {0} :: {1}" -f $rel, $_.Exception.Message)
  }

  if ((($i + 1) % 50) -eq 0 -or ($i + 1) -eq $total) {
    Write-Output ("Progress: {0}/{1} uploaded (ok={2}, fail={3})" -f ($i + 1), $total, $ok, $fail)
  }
}

Write-Output ("DONE: total={0}, ok={1}, fail={2}" -f $total, $ok, $fail)
