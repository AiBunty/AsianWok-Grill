param([string]$SheetId, [string]$OutFile)

$url = "https://docs.google.com/spreadsheets/d/$SheetId/gviz/tq?tqx=out:json;reqId:1&tq=select+*&headers=1"
$raw = (Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30).Content
$start = $raw.IndexOf('{')
$end   = $raw.LastIndexOf('}')
$json  = $raw.Substring($start, $end - $start + 1)
$data  = $json | ConvertFrom-Json

function gv($c,$i){
    if($c.Count -gt $i -and $c[$i] -ne $null -and $null -ne $c[$i].v){ [string]$c[$i].v } else { "" }
}

$cols = $data.table.cols | ForEach-Object { $_.label }
"Columns: " + ($cols -join " | ") | Out-File $OutFile -Encoding utf8

# Find column indexes by label
$colMap = @{}
for ($i = 0; $i -lt $cols.Count; $i++) { $colMap[$cols[$i]] = $i }

$catIdx   = if($colMap.ContainsKey("Category"))    { $colMap["Category"] }    else { 0 }
$nameIdx  = if($colMap.ContainsKey("Item Name"))   { $colMap["Item Name"] }   else { 1 }
$vegIdx   = if($colMap.ContainsKey("Veg"))         { $colMap["Veg"] }         else { -1 }
$jainIdx  = if($colMap.ContainsKey("Jain"))        { $colMap["Jain"] }        else { -1 }
$chefIdx1 = if($colMap.ContainsKey("Chef Special")){ $colMap["Chef Special"] } else { -1 }
$chefIdx2 = if($colMap.ContainsKey("Chef's Special")){ $colMap["Chef's Special"] } else { -1 }
$chefIdx  = if($chefIdx1 -ge 0){ $chefIdx1 } else { $chefIdx2 }
$nonVegCols = @("Chicken","Prawn","Mutton","Fish","Surmai","Pomfret","Crab","Egg") | Where-Object { $colMap.ContainsKey($_) } | ForEach-Object { $colMap[$_] }

$out = @()
foreach ($r in $data.table.rows) {
    $d    = $r.c
    $cat  = gv $d $catIdx
    $name = gv $d $nameIdx
    if (-not $name) { continue }

    $tags = @()
    if ($vegIdx  -ge 0 -and (gv $d $vegIdx)  -match '^\d') { $tags += "Veg" }
    if ($jainIdx -ge 0 -and (gv $d $jainIdx) -match '^\d') { $tags += "Jain" }
    $nvFound = $nonVegCols | Where-Object { (gv $d $_) -match '^\d' }
    if ($nvFound) { $tags += "NonVeg" }
    if ($chefIdx -ge 0 -and (gv $d $chefIdx) -match '(?i)^yes$') { $tags += "Chef Special" }

    $out += [PSCustomObject]@{ Category=$cat; Name=$name; Tags=($tags -join ", ") }
}

"Total: $($out.Count)" | Out-File $OutFile -Encoding utf8 -Append

$grouped = $out | Group-Object Category
foreach ($g in $grouped) {
    "" | Out-File $OutFile -Encoding utf8 -Append
    "=== $($g.Name) ===" | Out-File $OutFile -Encoding utf8 -Append
    foreach ($item in $g.Group) {
        "  - $($item.Name) [$($item.Tags)]" | Out-File $OutFile -Encoding utf8 -Append
    }
}

Write-Host "Done. $($out.Count) items written to $OutFile"
