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

function ci($name) { if($colMap.ContainsKey($name)){ [int]$colMap[$name] } else { -1 } }
function hasPrice($d,$i){ $i -ge 0 -and $d.Count -gt $i -and $d[$i] -ne $null -and ([string]$d[$i].v) -match '^\d' }

$catIdx  = ci "Category"
$nameIdx = ci "Item Name"
$vegIdx  = ci "Veg"
$jainIdx = ci "Jain"
$chefIdx = if((ci "Chef Special") -ge 0){ ci "Chef Special" } else { ci "Chef's Special" }

# Veg piece-count columns
$vegPcCols = @("Veg 2Pcs","Veg 4pcs","Veg 6pcs","Veg 9pcs","Veg 12pcs") | ForEach-Object { ci $_ } | Where-Object { $_ -ge 0 }

# NonVeg columns (single + piece-count)
$nonVegSingle = @("Chicken","Prawn","Mutton","Fish","Surmai","Pomfret","Crab","Egg") | ForEach-Object { ci $_ } | Where-Object { $_ -ge 0 }
$nonVegPc     = @("Chicken 2pcs","Chicken 4pcs","Chicken 6pcs","Chicken 9pcs","Chicken 12pcs","Prawns 2pcs","Prawns 4pcs","Prawns 6pcs","Prawns 9pcs","Prawns 12pcs") | ForEach-Object { ci $_ } | Where-Object { $_ -ge 0 }
$nonVegCols   = $nonVegSingle + $nonVegPc

# Name-based inference keywords
$nonVegWords = @('chicken','mutton','prawn','prawns','fish','egg','eggs','surmai','pomfret','crab','meat','shrimp','lollypop','tangdi','murgh','ghost','rogan josh','biryani mutton','biryani egg','pork','kebab chicken','seekh chicken','sea food','seafood','tuna','salmon','mahi','koliwada fish','bhuna mutton')
$vegOnlyWords  = @('veg','paneer','tofu','mushroom','spinach','corn','cheese','broccoli','dal','palak','potato','aloo','sabzi','subz','bageeche','chawal jeera','tandoori roti','naan','paratha','kulcha','pizza margarita','pizza corn','pizza tomato','pizza mushroom','gulab jamun','halwa','khichdi','pulao')

$catItems = [ordered]@{}
foreach ($r in $data.table.rows) {
    $d = @($r.c)
    $cat  = if($catIdx -ge 0 -and $d.Count -gt $catIdx  -and $d[$catIdx]  -ne $null){ [string]$d[$catIdx].v  } else { "Other" }
    $name = if($nameIdx -ge 0 -and $d.Count -gt $nameIdx -and $d[$nameIdx] -ne $null){ [string]$d[$nameIdx].v } else { "" }
    if (-not $name -or $name.Trim() -eq "") { continue }

    $isVeg    = (hasPrice $d $vegIdx) -or ($vegPcCols | Where-Object { hasPrice $d $_ })
    $isJain   = hasPrice $d $jainIdx
    $isNV     = ($nonVegCols | Where-Object { hasPrice $d $_ }) | Select-Object -First 1
    $isChef   = $chefIdx -ge 0 -and $d.Count -gt $chefIdx -and $d[$chefIdx] -ne $null -and ([string]$d[$chefIdx].v) -match '(?i)^yes$'

    # Name-based fallback for untagged items
    if (-not $isVeg -and -not $isNV) {
        $lname = $name.ToLower()
        $isNvName = $nonVegWords | Where-Object { $lname -match [regex]::Escape($_) } | Select-Object -First 1
        if ($isNvName) { $isNV = $true }
        else { $isVeg = $true }  # default to veg if no non-veg indicator
    }

    $tags = [System.Collections.Generic.List[string]]::new()
    if ($isVeg)  { $tags.Add("Veg") }
    if ($isJain) { $tags.Add("Jain") }
    if ($isNV)   { $tags.Add("NonVeg") }
    if ($isChef) { $tags.Add("Chef Special") }

    if (-not $catItems.Contains($cat)) { $catItems[$cat] = [System.Collections.Generic.List[string]]::new() }
    $catItems[$cat].Add("  - $name [$($tags -join ', ')]")
}

$total = 0
foreach ($k in $catItems.Keys) { $total += $catItems[$k].Count }

$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add("Total: $total")
foreach ($k in $catItems.Keys) {
    $lines.Add("")
    $lines.Add("=== $k ===")
    foreach ($li in $catItems[$k]) { $lines.Add($li) }
}
$lines | Out-File $OutFile -Encoding utf8
Write-Host "Done. $total items -> $OutFile"
