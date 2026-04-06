param([string]$SheetId, [string]$OutFile)

$url = "https://docs.google.com/spreadsheets/d/$SheetId/gviz/tq?tqx=out:json;reqId:1&tq=select+*&headers=1"
$raw = (Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30).Content
$start = $raw.IndexOf('{')
$end   = $raw.LastIndexOf('}')
$json  = $raw.Substring($start, $end - $start + 1)
$data  = $json | ConvertFrom-Json

$cols = @($data.table.cols | ForEach-Object { $_.label })
$colMap = @{}
for ($i = 0; $i -lt $cols.Count; $i++) { $colMap[$cols[$i]] = $i }

$catIdx  = if($colMap.ContainsKey("Category"))      { [int]$colMap["Category"] }      else { 0 }
$nameIdx = if($colMap.ContainsKey("Item Name"))     { [int]$colMap["Item Name"] }     else { 1 }
$vegIdx  = if($colMap.ContainsKey("Veg"))           { [int]$colMap["Veg"] }           else { -1 }
$jainIdx = if($colMap.ContainsKey("Jain"))          { [int]$colMap["Jain"] }          else { -1 }
$chefIdx = -1
if ($colMap.ContainsKey("Chef Special"))    { $chefIdx = [int]$colMap["Chef Special"] }
elseif ($colMap.ContainsKey("Chef's Special")) { $chefIdx = [int]$colMap["Chef's Special"] }
$nonVegCols = @()
foreach ($k in @("Chicken","Prawn","Mutton","Fish","Surmai","Pomfret","Crab","Egg")) {
    if ($colMap.ContainsKey($k)) { $nonVegCols += [int]$colMap[$k] }
}

$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add("Columns: " + ($cols -join " | "))

$catItems = [ordered]@{}
foreach ($r in $data.table.rows) {
    $d = @($r.c)
    $cat  = if($d.Count -gt $catIdx  -and $d[$catIdx]  -ne $null){ [string]$d[$catIdx].v  } else { "Other" }
    $name = if($d.Count -gt $nameIdx -and $d[$nameIdx] -ne $null){ [string]$d[$nameIdx].v } else { "" }
    if (-not $name -or $name.Trim() -eq "") { continue }

    $tags = [System.Collections.Generic.List[string]]::new()
    if ($vegIdx -ge 0  -and $d.Count -gt $vegIdx  -and $d[$vegIdx]  -ne $null -and ([string]$d[$vegIdx].v)  -match '^\d') { $tags.Add("Veg") }
    if ($jainIdx -ge 0 -and $d.Count -gt $jainIdx -and $d[$jainIdx] -ne $null -and ([string]$d[$jainIdx].v) -match '^\d') { $tags.Add("Jain") }
    foreach ($ni in $nonVegCols) {
        if ($d.Count -gt $ni -and $d[$ni] -ne $null -and ([string]$d[$ni].v) -match '^\d') { $tags.Add("NonVeg"); break }
    }
    if ($chefIdx -ge 0 -and $d.Count -gt $chefIdx -and $d[$chefIdx] -ne $null -and ([string]$d[$chefIdx].v) -match '(?i)^yes$') { $tags.Add("Chef Special") }

    if (-not $catItems.Contains($cat)) { $catItems[$cat] = [System.Collections.Generic.List[string]]::new() }
    $catItems[$cat].Add("  - $name [$($tags -join ", ")]")
}

$total = 0
foreach ($k in $catItems.Keys) { $total += $catItems[$k].Count }
$lines.Add("Total: $total")

foreach ($k in $catItems.Keys) {
    $lines.Add("")
    $lines.Add("=== $k ===")
    foreach ($li in $catItems[$k]) { $lines.Add($li) }
}

$lines | Out-File $OutFile -Encoding utf8
Write-Host "Done. $total items written to $OutFile"
