[CmdletBinding()]
param(
    [string]$Owner = 'timoinglin',
    [string]$Repo = 'wow-mop-registration',
    [string]$ReleaseAssetName = 'wow-legends-release.zip',
    [string]$PackageUrl = '',
    [string]$XamppRoot = 'C:\xampp',
    [string]$InstallRoot = '',
    [switch]$SkipBrowser,
    [switch]$NoPause
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# The updater ships inside the install, so default the target to its own
# folder. Resolve before anything else so logging/banner can show it.
if ([string]::IsNullOrWhiteSpace($InstallRoot)) {
    $InstallRoot = if ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }
}
$InstallRoot = (Resolve-Path -LiteralPath $InstallRoot).Path

$script:LogPath = Join-Path ([IO.Path]::GetTempPath()) ("wow-legends-update-{0}.log" -f (Get-Date -Format 'yyyyMMdd_HHmmss'))
$script:TranscriptStarted = $false

# -- Output helpers (kept ASCII; mirrors install.ps1) ------------------------
function Write-Rule {
    param([int]$Width = 78, [string]$Character = '=', [ConsoleColor]$Color = 'DarkCyan')
    Write-Host ($Character * $Width) -ForegroundColor $Color
}

function Write-Box {
    param([string]$Title, [string[]]$Lines = @(), [ConsoleColor]$BorderColor = 'DarkCyan', [ConsoleColor]$TextColor = 'Gray')
    $allLines = @($Title) + $Lines
    $innerWidth = (($allLines | ForEach-Object { $_.Length } | Measure-Object -Maximum).Maximum)
    if (-not $innerWidth) { $innerWidth = 20 }
    $border = '+' + ('-' * ($innerWidth + 2)) + '+'
    Write-Host $border -ForegroundColor $BorderColor
    Write-Host ('| ' + $Title.PadRight($innerWidth) + ' |') -ForegroundColor $BorderColor
    if ($Lines.Count -gt 0) {
        Write-Host ('| ' + ('-' * $innerWidth) + ' |') -ForegroundColor $BorderColor
        foreach ($line in $Lines) {
            Write-Host ('| ' + $line.PadRight($innerWidth) + ' |') -ForegroundColor $TextColor
        }
    }
    Write-Host $border -ForegroundColor $BorderColor
}

function Write-Step {
    param([string]$Message)
    Write-Host ''
    Write-Rule -Width 78 -Character '-' -Color 'DarkCyan'
    Write-Host ('=> ' + $Message) -ForegroundColor Cyan
    Write-Rule -Width 78 -Character '-' -Color 'DarkCyan'
}

function Write-Info { param([string]$Message) Write-Host "[INFO] $Message" -ForegroundColor Gray }
function Write-WarnMessage { param([string]$Message) Write-Host "[WARN] $Message" -ForegroundColor Yellow }
function Write-Ok { param([string]$Message) Write-Host "[ OK ] $Message" -ForegroundColor Green }

function Pause-BeforeExit {
    param([string]$Prompt = 'Press Enter to close this window')
    if (-not $NoPause) { Read-Host -Prompt $Prompt | Out-Null }
}

function Read-YesNo {
    param([string]$Prompt, [bool]$Default = $true)
    $suffix = if ($Default) { 'Y/n' } else { 'y/N' }
    while ($true) {
        $inputValue = Read-Host -Prompt "$Prompt [$suffix]"
        if ([string]::IsNullOrWhiteSpace($inputValue)) { return $Default }
        switch ($inputValue.Trim().ToLowerInvariant()) {
            'y' { return $true } 'yes' { return $true }
            'n' { return $false } 'no' { return $false }
            default { Write-WarnMessage 'Please answer yes or no.' }
        }
    }
}

function Start-UpdaterTranscript {
    try { Start-Transcript -Path $script:LogPath -Force | Out-Null; $script:TranscriptStarted = $true }
    catch { Write-WarnMessage ("Could not start transcript logging: {0}" -f $_.Exception.Message) }
}
function Stop-UpdaterTranscript {
    if ($script:TranscriptStarted) {
        try { Stop-Transcript | Out-Null } catch { }
        $script:TranscriptStarted = $false
    }
}

# -- Install fingerprint -----------------------------------------------------
function Assert-LooksLikeInstall {
    param([string]$Path)
    $sentinels = @(
        'index.php', 'config.sample.php', 'sql\setup.sql',
        'includes\db.php', 'templates\header.php', 'pages'
    )
    $missing = @()
    foreach ($s in $sentinels) {
        if (-not (Test-Path -LiteralPath (Join-Path $Path $s))) { $missing += $s }
    }
    if ($missing.Count -gt 0) {
        throw ("'{0}' does not look like a WoW Legends install (missing: {1}). Run update.ps1 from inside the website folder, or pass -InstallRoot." -f $Path, ($missing -join ', '))
    }
}

# -- Apache detection / stop -------------------------------------------------
function Test-ApacheRunning {
    $proc = @(Get-Process -Name 'httpd' -ErrorAction SilentlyContinue)
    if ($proc.Count -gt 0) { return $true }
    # Best-effort port probe (covers an Apache started under a different name)
    foreach ($port in 80, 443) {
        try {
            $client = New-Object Net.Sockets.TcpClient
            $iar = $client.BeginConnect('127.0.0.1', $port, $null, $null)
            $ok = $iar.AsyncWaitHandle.WaitOne(400)
            if ($ok -and $client.Connected) { $client.Close(); return $true }
            $client.Close()
        } catch { }
    }
    return $false
}

function Stop-Apache {
    param([string]$Root)
    # Kill httpd.exe directly: fast, reliable, fully non-interactive. We do NOT
    # call apache_stop.bat -- some XAMPP variants keep that window open, so a
    # hidden Start-Process -Wait would hang forever. All we need is httpd not
    # holding file locks while we overwrite; terminating the process does that.
    $proc = @(Get-Process -Name 'httpd' -ErrorAction SilentlyContinue)
    if ($proc.Count -gt 0) {
        Write-Info ("Stopping Apache (terminating {0} httpd process(es))..." -f $proc.Count)
        try {
            $proc | Stop-Process -Force -ErrorAction Stop
        } catch {
            # Last resort if Stop-Process is blocked (permissions/AV).
            & cmd /c 'taskkill /F /IM httpd.exe >nul 2>&1'
        }
    }
    for ($i = 0; $i -lt 20; $i++) {
        if (-not (Test-ApacheRunning)) { return $true }
        Start-Sleep -Milliseconds 500
    }
    # Still up after ~10s -> almost certainly Apache installed as a Windows
    # service that the SCM keeps respawning. Tell the caller to stop it by hand.
    return (-not (Test-ApacheRunning))
}

function Start-Apache {
    param([string]$Root)
    $startBat = Join-Path $Root 'apache_start.bat'
    if (Test-Path -LiteralPath $startBat) { Start-Process -FilePath $startBat -WindowStyle Hidden; return 'apache_start.bat' }
    $ctrl = Join-Path $Root 'xampp-control.exe'
    if (Test-Path -LiteralPath $ctrl) { Start-Process -FilePath $ctrl; return 'xampp-control.exe' }
    return $null
}

# -- Full backup zip (the safety net) ----------------------------------------
# Mirrors the install into a temp staging dir with robocopy (robust excludes:
# .git, node_modules, *.log, prior backup zips), then zips that staging dir.
# Uses the ZipFile.CreateFromDirectory(string,string) overload, which lives in
# System.IO.Compression.FileSystem ALONE and needs no enum -- the earlier
# ZipArchiveMode/CompressionLevel enums live in a *different* assembly
# (System.IO.Compression) that isn't always auto-loaded in Windows PowerShell.
# Falls back to Compress-Archive if the .NET API is unavailable. The zip lands
# beside the install folder so it is outside the overwrite target.
function New-FullBackupZip {
    param([string]$Path)

    $stamp   = Get-Date -Format 'yyyyMMdd-HHmmss'
    $zipName = "wow-legends-backup-$stamp.zip"
    $zipPath = Join-Path (Split-Path -Parent $Path) $zipName
    $staging = Join-Path ([IO.Path]::GetTempPath()) ("wlbk-{0}" -f [Guid]::NewGuid().ToString('N'))

    Write-Info 'Collecting files to back up (this can take a while with background videos)...'
    $rc = @(
        $Path, $staging, '/E',
        '/XD', (Join-Path $Path '.git'), (Join-Path $Path 'node_modules'),
        '/XF', '*.log', 'wow-legends-backup-*.zip',
        '/R:1', '/W:1', '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
    )
    & robocopy @rc | Out-Null
    $rcCode = $LASTEXITCODE
    $global:LASTEXITCODE = 0
    if ($rcCode -ge 8) {
        if (Test-Path -LiteralPath $staging) { Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue }
        throw "robocopy failed building the backup staging copy (exit $rcCode)."
    }

    $count = @(Get-ChildItem -LiteralPath $staging -Recurse -File -Force -ErrorAction SilentlyContinue).Count
    if ($count -eq 0) {
        Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue
        throw "Nothing to back up in '$Path'."
    }
    Write-Info ("Compressing {0} files..." -f $count)

    try {
        $made = $false
        try {
            Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction Stop
            [System.IO.Compression.ZipFile]::CreateFromDirectory($staging, $zipPath)
            $made = $true
        } catch {
            Write-WarnMessage 'System.IO.Compression unavailable; using Compress-Archive instead...'
        }
        if (-not $made) {
            if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force -ErrorAction SilentlyContinue }
            Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $zipPath -Force
        }
    } finally {
        Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue
    }

    if (-not (Test-Path -LiteralPath $zipPath)) { throw 'Backup zip was not created.' }
    $sizeMB = [Math]::Round((Get-Item -LiteralPath $zipPath).Length / 1MB, 1)
    Write-Ok ("Full backup written: {0} ({1} MB)" -f $zipPath, $sizeMB)
    return $zipPath
}

# Quick targeted copy-aside for fast partial rollback of user data.
function New-DataCopyAside {
    param([string]$Path)
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $dest  = Join-Path (Split-Path -Parent $Path) "wow-legends-restore-$stamp"
    New-Item -ItemType Directory -Path $dest | Out-Null
    foreach ($item in @('config.php', 'uploads', 'cache')) {
        $src = Join-Path $Path $item
        if (Test-Path -LiteralPath $src) {
            Copy-Item -LiteralPath $src -Destination $dest -Recurse -Force
        }
    }
    return $dest
}

# -- GitHub release download (mirrors install.ps1) ---------------------------
function Get-ReleaseInfo {
    param([string]$RepositoryOwner, [string]$RepositoryName)
    $headers = @{ 'User-Agent' = 'WoWLegendsUpdater' }
    return Invoke-RestMethod -Headers $headers -Uri "https://api.github.com/repos/$RepositoryOwner/$RepositoryName/releases/latest"
}

function Get-ReleasePackageUrl {
    param($ReleaseInfo, [string]$AssetName, [string]$ExplicitUrl)
    if (-not [string]::IsNullOrWhiteSpace($ExplicitUrl)) { return $ExplicitUrl }
    $asset = $ReleaseInfo.assets | Where-Object { $_.name -eq $AssetName } | Select-Object -First 1
    if ($asset) { return $asset.browser_download_url }
    if ([string]::IsNullOrWhiteSpace($ReleaseInfo.zipball_url)) {
        throw "Latest release has no '$AssetName' asset and no source zip URL. Rerun with -PackageUrl."
    }
    Write-Info "Named asset not found; using GitHub source zip (tracked files only - safe)."
    return $ReleaseInfo.zipball_url
}

function Get-ExpandedContentRoot {
    param([string]$ExtractPath)
    $items = @(Get-ChildItem -LiteralPath $ExtractPath -Force)
    if ($items.Count -eq 1 -and $items[0].PSIsContainer) { return $items[0].FullName }
    return $ExtractPath
}

# Overwrite app files with robocopy (built into Windows). The release zip is a
# git archive: it contains ONLY tracked files - no config.php, no uploaded
# files, no cache JSON - and robocopy runs WITHOUT /PURGE, so it only writes
# files that exist in the release. Anything present only in the install
# (config.php, uploads/, cache/, .git/, backup zips) is left untouched
# automatically. .git is also explicitly excluded for custom -PackageUrl
# safety. (Plain Copy-Item -Recurse would nest existing folders like
# pages\pages on an in-place overwrite - robocopy merges correctly.)
function Copy-OverInstall {
    param([string]$Source, [string]$Destination)
    $rcArgs = @(
        $Source, $Destination, '/E', '/XD', (Join-Path $Source '.git'),
        '/R:1', '/W:1', '/NFL', '/NDL', '/NJH', '/NJS', '/NP'
    )
    & robocopy @rcArgs | Out-Null
    $code = $LASTEXITCODE
    # robocopy exit codes: 0-7 = success (bit flags), 8+ = a real failure.
    if ($code -ge 8) {
        throw "robocopy failed while applying program files (exit $code)."
    }
    $global:LASTEXITCODE = 0
}

# -- PHP helpers run with the BUNDLED XAMPP php (mysql client not required) ---
function New-SqlRunner {
    param([string]$Path)
    $php = @'
<?php
// Args: <config.php path> <setup.sql path>. Runs the idempotent schema using
// the live config's auth DB credentials. Same line-buffered splitter the
// installer uses (handles SET/PREPARE/EXECUTE blocks in setup.sql).
$cfgPath = $argv[1] ?? ''; $sqlPath = $argv[2] ?? '';
$out = ['ok' => false, 'message' => ''];
try {
    if (!is_file($cfgPath)) throw new Exception("config.php not found");
    if (!is_file($sqlPath)) throw new Exception("sql/setup.sql not found");
    $cfg = require $cfgPath;
    $d = $cfg['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['port'], $d['name_auth']);
    $pdo = new PDO($dsn, $d['user'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lines = file($sqlPath, FILE_IGNORE_NEW_LINES);
    $buf = '';
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '--') === 0) continue;
        $buf .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') { $pdo->exec($buf); $buf = ''; }
    }
    if (trim($buf) !== '') $pdo->exec($buf);
    $out['ok'] = true; $out['message'] = 'Schema applied (idempotent).';
} catch (Throwable $e) {
    $out['ok'] = false; $out['message'] = $e->getMessage();
}
echo json_encode($out);
exit($out['ok'] ? 0 : 1);
'@
    Set-Content -LiteralPath $Path -Value $php -Encoding ASCII
}

function New-ConfigDiffRunner {
    param([string]$Path)
    $php = @'
<?php
// Args: <config.php> <config.sample.php>. Reports associative keys present in
// the sample but missing from the live config (dot-notated). No auto-merge.
$cur = @require ($argv[1] ?? '');
$smp = @require ($argv[2] ?? '');
function flat($a, $p = '') {
    $o = [];
    if (!is_array($a)) return $o;
    foreach ($a as $k => $v) {
        if (is_int($k)) continue;
        $kk = $p === '' ? $k : "$p.$k";
        if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
            $o = array_merge($o, flat($v, $kk));
        } else { $o[$kk] = true; }
    }
    return $o;
}
$miss = array_keys(array_diff_key(flat($smp), flat(is_array($cur) ? $cur : [])));
echo json_encode(['missing' => $miss]);
'@
    Set-Content -LiteralPath $Path -Value $php -Encoding ASCII
}

function Invoke-PhpJson {
    param([string]$PhpExe, [string]$Script, [string[]]$PhpArgs)
    $raw = & $PhpExe $Script @PhpArgs 2>&1 | ForEach-Object { $_.ToString() }
    $code = $LASTEXITCODE
    $text = ($raw -join "`n")
    $start = $text.IndexOf('{')
    if ($start -lt 0) { return @{ Ok = $false; Json = $null; Code = $code; Raw = $text } }
    try { $json = $text.Substring($start) | ConvertFrom-Json } catch { return @{ Ok = $false; Json = $null; Code = $code; Raw = $text } }
    return @{ Ok = ($code -eq 0); Json = $json; Code = $code; Raw = $text }
}

# ============================================================================
try {
    Start-UpdaterTranscript
    Clear-Host
    Write-Rule
    Write-Host ' WoW Legends One-Click Updater' -ForegroundColor Cyan
    Write-Host ' Updates an existing install to the latest release' -ForegroundColor Gray
    Write-Host (" Target : {0}" -f $InstallRoot) -ForegroundColor DarkGray
    Write-Host (" Log    : {0}" -f $script:LogPath) -ForegroundColor DarkGray
    Write-Rule

    Write-Step 'Verifying this is a WoW Legends install'
    Assert-LooksLikeInstall -Path $InstallRoot
    Write-Ok 'Install folder verified.'

    $phpExe = Join-Path $XamppRoot 'php\php.exe'
    if (-not (Test-Path -LiteralPath $phpExe)) {
        throw "Bundled PHP not found at '$phpExe'. Pass -XamppRoot if XAMPP is elsewhere."
    }

    # Current / target versions
    $versionFile = Join-Path $InstallRoot 'VERSION'
    $currentVersion = if (Test-Path -LiteralPath $versionFile) { (Get-Content -LiteralPath $versionFile -Raw).Trim() } else { '(not recorded - first updater run)' }

    Write-Step 'Checking the latest release'
    $releaseInfo = Get-ReleaseInfo -RepositoryOwner $Owner -RepositoryName $Repo
    $newVersion = if ($releaseInfo.tag_name) { $releaseInfo.tag_name } else { '(unknown)' }

    Write-Box -Title 'Update Summary' -Lines @(
        "Installed : $currentVersion",
        "Latest    : $newVersion",
        '',
        'This updater will:',
        '1. Stop Apache (files get locked while it serves).',
        '2. Make a FULL zip backup of the whole website folder.',
        '3. Copy config.php / uploads / cache aside for fast rollback.',
        '4. Download the latest release and overwrite program files.',
        '   (config.php, uploads/, cache/, .git/ are NOT touched.)',
        '5. Apply sql/setup.sql (idempotent) via the bundled PHP.',
        '6. Report any new config keys, restart Apache, verify.'
    ) -BorderColor Cyan -TextColor Gray

    Write-Box -Title 'Not Overwritten (your data is safe)' -Lines @(
        'config.php           - your settings',
        'uploads/             - avatars, news/forum images, attachments',
        'cache/               - runtime counters',
        '.git/                - kept intact for git-cloned installs',
        'Customised assets in assets/, lang/, CSS ARE replaced -',
        'that is exactly why the full backup zip is made first.'
    ) -BorderColor Yellow -TextColor White

    if (-not (Read-YesNo 'Proceed with the update?' $false)) {
        Write-WarnMessage 'Update cancelled. Nothing was changed.'
        Pause-BeforeExit
        exit 0
    }

    # -- Apache must be down -------------------------------------------------
    Write-Step 'Stopping Apache'
    if (Test-ApacheRunning) {
        $auto = Read-YesNo 'Apache is running. Stop it automatically now?' $true
        if ($auto) {
            if (-not (Stop-Apache -Root $XamppRoot)) {
                throw 'Could not stop Apache automatically. Stop it from the XAMPP Control Panel and re-run.'
            }
            Write-Ok 'Apache stopped.'
        } else {
            Write-WarnMessage 'Stop Apache from the XAMPP Control Panel now. Waiting...'
            while (Test-ApacheRunning) { Start-Sleep -Seconds 3 }
            Write-Ok 'Apache is down.'
        }
    } else {
        Write-Info 'Apache is not running.'
    }

    # -- Backups -------------------------------------------------------------
    Write-Step 'Backing up the entire website folder'
    $backupZip = New-FullBackupZip -Path $InstallRoot
    $copyAside = New-DataCopyAside -Path $InstallRoot
    Write-Info ("Quick-rollback copy of config.php/uploads/cache: {0}" -f $copyAside)

    # -- Download release ----------------------------------------------------
    Write-Step 'Downloading the latest release'
    $tempRoot = Join-Path ([IO.Path]::GetTempPath()) ("wow-legends-update-{0}" -f [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tempRoot | Out-Null
    $zipPath = Join-Path $tempRoot 'package.zip'
    $extractPath = Join-Path $tempRoot 'package'
    $packageSource = Get-ReleasePackageUrl -ReleaseInfo $releaseInfo -AssetName $ReleaseAssetName -ExplicitUrl $PackageUrl
    Invoke-WebRequest -Headers @{ 'User-Agent' = 'WoWLegendsUpdater' } -Uri $packageSource -OutFile $zipPath
    Expand-Archive -LiteralPath $zipPath -DestinationPath $extractPath -Force
    $contentRoot = Get-ExpandedContentRoot -ExtractPath $extractPath
    if (-not (Test-Path -LiteralPath (Join-Path $contentRoot 'index.php'))) {
        throw 'Downloaded package does not look valid (no index.php at its root). Aborted before overwriting anything.'
    }
    Write-Ok 'Release downloaded and extracted.'

    # -- Overwrite (point of change) -----------------------------------------
    Write-Step 'Applying program files'
    try {
        Copy-OverInstall -Source $contentRoot -Destination $InstallRoot
        Write-Ok 'Program files updated.'
    } catch {
        Write-Box -Title 'Overwrite Failed - Rolling Back' -Lines @(
            $_.Exception.Message,
            '',
            'Restore your previous install from the full backup zip:',
            $backupZip
        ) -BorderColor Red -TextColor White
        throw
    }

    # Stamp the version for next time.
    Set-Content -LiteralPath $versionFile -Value $newVersion -Encoding ASCII

    # -- Schema --------------------------------------------------------------
    Write-Step 'Applying database schema (idempotent)'
    $sqlRunner = Join-Path $tempRoot 'run-sql.php'
    New-SqlRunner -Path $sqlRunner
    $sqlRes = Invoke-PhpJson -PhpExe $phpExe -Script $sqlRunner -PhpArgs @((Join-Path $InstallRoot 'config.php'), (Join-Path $InstallRoot 'sql\setup.sql'))
    if ($sqlRes.Ok -and $sqlRes.Json -and $sqlRes.Json.ok) {
        Write-Ok ("Schema: {0}" -f $sqlRes.Json.message)
    } else {
        $msg = if ($sqlRes.Json) { $sqlRes.Json.message } else { $sqlRes.Raw }
        Write-WarnMessage ("Schema step did not complete: {0}" -f $msg)
        Write-WarnMessage 'Program files are updated. Apply sql/setup.sql manually (phpMyAdmin) if needed - it is idempotent.'
    }

    # -- New config keys report (no auto-merge) ------------------------------
    Write-Step 'Checking for new config keys'
    $diffRunner = Join-Path $tempRoot 'config-diff.php'
    New-ConfigDiffRunner -Path $diffRunner
    $diff = Invoke-PhpJson -PhpExe $phpExe -Script $diffRunner -PhpArgs @((Join-Path $InstallRoot 'config.php'), (Join-Path $InstallRoot 'config.sample.php'))
    if ($diff.Json -and $diff.Json.missing -and @($diff.Json.missing).Count -gt 0) {
        Write-Box -Title 'New config.php Keys To Add Manually' -Lines (@(
            'config.sample.php has keys your config.php is missing.',
            'Your config.php was NOT modified. Add these by hand:'
        ) + @($diff.Json.missing | ForEach-Object { "  - $_" })) -BorderColor Yellow -TextColor White
    } else {
        Write-Ok 'No new config keys - your config.php is complete.'
    }

    # -- Restart + verify ----------------------------------------------------
    Write-Step 'Restarting Apache'
    $startMethod = Start-Apache -Root $XamppRoot
    if ($null -eq $startMethod) {
        Write-WarnMessage 'Could not find an Apache start script - start Apache from the XAMPP Control Panel.'
    } else {
        Write-Info "Apache start triggered via $startMethod."
        Start-Sleep -Seconds 4
    }

    $verifyOk = $false
    try {
        $resp = Invoke-WebRequest -Uri 'http://localhost/' -UseBasicParsing -TimeoutSec 12
        $verifyOk = ($resp.StatusCode -eq 200) -and ($resp.Content -notmatch 'Fatal error|Parse error')
    } catch { $verifyOk = $false }

    if (-not $SkipBrowser) { Start-Process 'http://localhost/' | Out-Null }

    if ($verifyOk) {
        Write-Box -Title 'Update Complete' -Lines @(
            "Updated:  $currentVersion  ->  $newVersion",
            'Site responded 200 OK at http://localhost/.',
            '',
            "Full backup : $backupZip",
            "Data aside  : $copyAside",
            "Log file    : $script:LogPath",
            '',
            'You can delete the backup/aside once the site looks good.'
        ) -BorderColor Green -TextColor White
    } else {
        Write-Box -Title 'Update Applied - VERIFY MANUALLY' -Lines @(
            "Version:  $currentVersion  ->  $newVersion",
            'Files updated but http://localhost/ did not return a clean 200.',
            'Open the site and check. If it is broken, restore from:',
            $backupZip,
            '(unzip it back over the website folder).',
            "Log file: $script:LogPath"
        ) -BorderColor Yellow -TextColor White
    }

    Pause-BeforeExit
}
catch {
    Write-Host ''
    Write-Box -Title 'Updater Error' -Lines @(
        'The updater hit an error and stopped.',
        $_.Exception.Message,
        '',
        'If program files were already overwritten, restore the full',
        'backup zip (created before any change) over the folder:',
        ("  {0}" -f $script:LogPath),
        'Backup path is printed above under the backup step.'
    ) -BorderColor Red -TextColor White
    Pause-BeforeExit -Prompt 'Press Enter to close after reviewing the error'
    exit 1
}
finally {
    Stop-UpdaterTranscript
}
