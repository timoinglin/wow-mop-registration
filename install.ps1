[CmdletBinding()]
param(
    [string]$Owner = 'timoinglin',
    [string]$Repo = 'wow-mop-registration',
    [string]$ReleaseAssetName = 'wow-legends-release.zip',
    [string]$PackageUrl = '',
    [string]$XamppRoot = 'C:\xampp',
    [string]$InstallRoot = 'C:\xampp\htdocs',
    [switch]$AllowExistingXampp,
    [switch]$SkipBrowser
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Write-Rule {
    param(
        [int]$Width = 78,
        [string]$Character = '=',
        [ConsoleColor]$Color = 'DarkCyan'
    )

    Write-Host ($Character * $Width) -ForegroundColor $Color
}

function Write-Box {
    param(
        [string]$Title,
        [string[]]$Lines = @(),
        [ConsoleColor]$BorderColor = 'DarkCyan',
        [ConsoleColor]$TextColor = 'Gray'
    )

    $allLines = @($Title) + $Lines
    $innerWidth = (($allLines | ForEach-Object { $_.Length } | Measure-Object -Maximum).Maximum)
    if (-not $innerWidth) {
        $innerWidth = 20
    }

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

function Show-Banner {
    Clear-Host
    Write-Rule
    Write-Host ' WoW Legends One-Click Installer' -ForegroundColor Cyan
    Write-Host ' Fresh local website setup for TrinityCore-based repacks' -ForegroundColor Gray
    Write-Rule
}

function Show-IntroPanel {
    Write-Box -Title 'Before You Continue' -Lines @(
        'This installer sets up XAMPP and the website only.',
        'It does not install or configure the WoW repack itself.',
        'Your WoW repack should already be installed.',
        'Its MySQL server should already be running.',
        'If you still need a repack, get one from https://www.emucoach.com/'
    ) -BorderColor Yellow -TextColor White

    Write-Box -Title 'What This Installer Will Do' -Lines @(
        '1. Install XAMPP 8.2 if it is not already present.',
        '2. Download the latest prepared release ZIP.',
        '3. Deploy the app into C:\xampp\htdocs\.',
        '4. Enable required PHP extensions in php.ini.',
        '5. Generate config.php with safe default feature flags.',
        '6. Test DB access and optionally import sql/setup.sql.',
        '7. Start Apache and open http://localhost/.'
    ) -BorderColor Cyan -TextColor Gray
}

function Write-Step {
    param([string]$Message)
    Write-Host ''
    Write-Rule -Width 78 -Character '-' -Color 'DarkCyan'
    Write-Host ('=> ' + $Message) -ForegroundColor Cyan
    Write-Rule -Width 78 -Character '-' -Color 'DarkCyan'
}

function Write-Info {
    param([string]$Message)
    Write-Host "[INFO] $Message" -ForegroundColor Gray
}

function Write-WarnMessage {
    param([string]$Message)
    Write-Host "[WARN] $Message" -ForegroundColor Yellow
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Read-PlainSecret {
    param([string]$Prompt)

    $secureValue = Read-Host -Prompt $Prompt -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureValue)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}

function Read-ValueWithDefault {
    param(
        [string]$Prompt,
        [string]$Default,
        [switch]$Secret
    )

    if ($Secret) {
        $value = Read-PlainSecret "$Prompt [$Default]"
    } else {
        $value = Read-Host -Prompt "$Prompt [$Default]"
    }

    if ([string]::IsNullOrWhiteSpace($value)) {
        return $Default
    }

    return $value
}

function Read-YesNo {
    param(
        [string]$Prompt,
        [bool]$Default = $true
    )

    $suffix = if ($Default) { 'Y/n' } else { 'y/N' }
    while ($true) {
        $inputValue = Read-Host -Prompt "$Prompt [$suffix]"
        if ([string]::IsNullOrWhiteSpace($inputValue)) {
            return $Default
        }

        switch ($inputValue.Trim().ToLowerInvariant()) {
            'y' { return $true }
            'yes' { return $true }
            'n' { return $false }
            'no' { return $false }
            default { Write-WarnMessage 'Please answer yes or no.' }
        }
    }
}

function Assert-CommandExists {
    param([string]$CommandName)

    if (-not (Get-Command $CommandName -ErrorAction SilentlyContinue)) {
        throw "Required command '$CommandName' was not found."
    }
}

function Get-ReleasePackageUrl {
    param(
        [string]$RepositoryOwner,
        [string]$RepositoryName,
        [string]$AssetName,
        [string]$ExplicitUrl
    )

    if (-not [string]::IsNullOrWhiteSpace($ExplicitUrl)) {
        return $ExplicitUrl
    }

    $headers = @{ 'User-Agent' = 'WoWLegendsInstaller' }
    $releaseInfo = Invoke-RestMethod -Headers $headers -Uri "https://api.github.com/repos/$RepositoryOwner/$RepositoryName/releases/latest"
    $asset = $releaseInfo.assets | Where-Object { $_.name -eq $AssetName } | Select-Object -First 1

    if (-not $asset) {
        throw "Latest release does not contain '$AssetName'. Publish a prepared release ZIP with dependencies included, or rerun with -PackageUrl."
    }

    return $asset.browser_download_url
}

function Download-File {
    param(
        [string]$Url,
        [string]$DestinationPath
    )

    $headers = @{ 'User-Agent' = 'WoWLegendsInstaller' }
    Invoke-WebRequest -Headers $headers -Uri $Url -OutFile $DestinationPath
}

function Get-ExpandedContentRoot {
    param([string]$ExtractPath)

    $items = Get-ChildItem -LiteralPath $ExtractPath -Force
    if ($items.Count -eq 1 -and $items[0].PSIsContainer) {
        return $items[0].FullName
    }

    return $ExtractPath
}

function Backup-InstallRootIfNeeded {
    param([string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
        return $null
    }

    $existingItems = Get-ChildItem -LiteralPath $Path -Force
    if ($existingItems.Count -eq 0) {
        return $null
    }

    if (-not (Read-YesNo "'$Path' already contains files. Back them up and continue?" $true)) {
        throw 'Installer cancelled by user.'
    }

    $backupPath = Join-Path (Split-Path -Parent $Path) ("htdocs_backup_{0}" -f (Get-Date -Format 'yyyyMMdd_HHmmss'))
    New-Item -ItemType Directory -Path $backupPath | Out-Null

    foreach ($item in $existingItems) {
        Move-Item -LiteralPath $item.FullName -Destination $backupPath -Force
    }

    return $backupPath
}

function Copy-DirectoryContents {
    param(
        [string]$Source,
        [string]$Destination
    )

    foreach ($item in Get-ChildItem -LiteralPath $Source -Force) {
        Copy-Item -LiteralPath $item.FullName -Destination $Destination -Recurse -Force
    }
}

function Enable-PHPExtensions {
    param(
        [string]$PhpIniPath,
        [string[]]$Extensions
    )

    $lines = Get-Content -LiteralPath $PhpIniPath

    foreach ($extension in $Extensions) {
        $pattern = "(?i)^\s*;?\s*extension\s*=\s*(?:php_)?{0}(?:\.dll)?\s*$" -f [Regex]::Escape($extension)
        $matchIndexes = for ($index = 0; $index -lt $lines.Count; $index++) {
            if ($lines[$index] -match $pattern) {
                $index
            }
        }

        if ($matchIndexes.Count -eq 0) {
            $lines += "extension=$extension"
            continue
        }

        $firstMatchIndex = $matchIndexes[0]
        $lines[$firstMatchIndex] = "extension=$extension"

        if ($matchIndexes.Count -gt 1) {
            $duplicateIndexes = @($matchIndexes | Select-Object -Skip 1)
            $lines = for ($index = 0; $index -lt $lines.Count; $index++) {
                if ($duplicateIndexes -notcontains $index) {
                    $lines[$index]
                }
            }
        }
    }

    Set-Content -LiteralPath $PhpIniPath -Value $lines -Encoding ASCII
}

function New-InstallerConfig {
    param(
        [string]$TemplatePath,
        [string]$ConfigPath,
        [hashtable]$DbSettings,
        [string]$BaseUrl
    )

    Copy-Item -LiteralPath $TemplatePath -Destination $ConfigPath -Force
    $content = Get-Content -LiteralPath $ConfigPath -Raw

    $replacements = @(
        @{ Pattern = "'host'\s*=>\s*'127\.0\.0\.1'"; Replacement = "'host'       => '$($DbSettings.Host)'" },
        @{ Pattern = "'port'\s*=>\s*'3306'"; Replacement = "'port'       => '$($DbSettings.Port)'" },
        @{ Pattern = "'user'\s*=>\s*'root'"; Replacement = "'user'       => '$($DbSettings.User)'" },
        @{ Pattern = "'password'\s*=>\s*'ascent'"; Replacement = "'password'   => '$($DbSettings.Password)'" },
        @{ Pattern = "'name_auth'\s*=>\s*'auth'"; Replacement = "'name_auth'  => '$($DbSettings.AuthDatabase)'" },
        @{ Pattern = "'name_chars'\s*=>\s*'characters'"; Replacement = "'name_chars' => '$($DbSettings.CharactersDatabase)'" },
        @{ Pattern = "'name'\s*=>\s*'Your Server Name'"; Replacement = "'name' => 'WoW Server'" },
        @{ Pattern = "'description'\s*=>\s*'Your Server Description \(e\.g\. x2 XP, Progressive Release\)'"; Replacement = "'description' => 'Local installation created by the one-click installer'" },
        @{ Pattern = "'realmlist'\s*=>\s*'logon\.yourserver\.com'"; Replacement = "'realmlist' => '127.0.0.1'" },
        @{ Pattern = "'title'\s*=>\s*'Your Server - Register'"; Replacement = "'title'    => 'WoW Server - Register'" },
        @{ Pattern = "'base_url'\s*=>\s*'http://yourserver\.com'"; Replacement = "'base_url' => '$BaseUrl'" },
        @{ Pattern = "'download_link'\s*=>\s*'https://example\.com/your-client-download-link'"; Replacement = "'download_link' => ''" },
        @{ Pattern = "'recaptcha'\s*=>\s*true,\s*// Google reCAPTCHA on all forms"; Replacement = "'recaptcha'        => false, // Google reCAPTCHA on all forms" },
        @{ Pattern = "'recover_password'\s*=>\s*true,\s*// Password recovery via email \(requires SMTP\)"; Replacement = "'recover_password' => false, // Password recovery via email (requires SMTP)" },
        @{ Pattern = "'tickets'\s*=>\s*true,\s*// Support ticket system"; Replacement = "'tickets'          => false, // Support ticket system" },
        @{ Pattern = "'discord'\s*=>\s*'https://discord\.gg/your-invite'"; Replacement = "'discord'   => ''" },
        @{ Pattern = "'youtube'\s*=>\s*'https://www\.youtube\.com/'"; Replacement = "'youtube'   => ''" },
        @{ Pattern = "'twitter'\s*=>\s*'https://x\.com/home'"; Replacement = "'twitter'   => ''" },
        @{ Pattern = "'instagram'\s*=>\s*'https://www\.instagram\.com/'"; Replacement = "'instagram' => ''" }
    )

    foreach ($replacement in $replacements) {
        $regex = [Regex]::new($replacement.Pattern)
        $content = $regex.Replace($content, $replacement.Replacement, 1)
    }

    Set-Content -LiteralPath $ConfigPath -Value $content -Encoding ASCII
}

function New-DatabaseHelperFile {
    param([string]$Path)

    $helperContent = @'
<?php
$settingsPath = $argv[1] ?? '';
if ($settingsPath === '' || !file_exists($settingsPath)) {
    fwrite(STDERR, "Missing settings file.\n");
    exit(2);
}

$settings = json_decode(file_get_contents($settingsPath), true);
if (!is_array($settings)) {
    fwrite(STDERR, "Invalid settings JSON.\n");
    exit(3);
}

$result = [
    'ok' => false,
    'auth' => ['ok' => false, 'message' => 'Not tested'],
    'chars' => ['ok' => false, 'message' => 'Not tested'],
    'import' => ['attempted' => false, 'ok' => false, 'message' => 'Skipped'],
];

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

function try_connection(array $db, string $name, array $options): array {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']);
        $pdo = new PDO($dsn, $db['user'], $db['password'], $options);
        $pdo->query('SELECT 1');
        return ['ok' => true, 'message' => sprintf('Connected to %s database.', $name), 'pdo' => $pdo];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'pdo' => null];
    }
}

function import_sql(PDO $pdo, string $sqlFile): array {
    if (!file_exists($sqlFile)) {
        return ['ok' => false, 'message' => 'SQL file was not found.'];
    }

    $lines = file($sqlFile, FILE_IGNORE_NEW_LINES);
    $buffer = '';

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }

        $buffer .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $pdo->exec($buffer);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $pdo->exec($buffer);
    }

    return ['ok' => true, 'message' => 'Support tables imported successfully.'];
}

$authConnection = try_connection($settings['auth'], 'auth', $options);
$charsConnection = try_connection($settings['chars'], 'characters', $options);

$result['auth']['ok'] = $authConnection['ok'];
$result['auth']['message'] = $authConnection['message'];
$result['chars']['ok'] = $charsConnection['ok'];
$result['chars']['message'] = $charsConnection['message'];

if (!empty($settings['import']) && $authConnection['ok']) {
    $result['import']['attempted'] = true;
    try {
        $importResult = import_sql($authConnection['pdo'], $settings['sqlFile']);
        $result['import']['ok'] = $importResult['ok'];
        $result['import']['message'] = $importResult['message'];
    } catch (Throwable $e) {
        $result['import']['ok'] = false;
        $result['import']['message'] = $e->getMessage();
    }
}

$result['ok'] = $result['auth']['ok'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit($result['ok'] ? 0 : 1);
'@

    Set-Content -LiteralPath $Path -Value $helperContent -Encoding ASCII
}

function Invoke-DatabaseHelper {
    param(
        [string]$PhpExePath,
        [string]$HelperPath,
        [string]$SettingsPath
    )

    $outputLines = & $PhpExePath $HelperPath $SettingsPath 2>&1 | ForEach-Object { $_.ToString() }
    $exitCode = $LASTEXITCODE

    if (-not $outputLines -or ($outputLines -join '').Trim().Length -eq 0) {
        return @{
            Result = [pscustomobject]@{
                ok = $false
                auth = [pscustomobject]@{ ok = $false; message = 'Database helper did not return any output.' }
                chars = [pscustomobject]@{ ok = $false; message = 'Database helper did not run.' }
                import = [pscustomobject]@{ attempted = $false; ok = $false; message = 'Skipped' }
            }
            ExitCode = $exitCode
            PreludeLines = @()
            RawOutput = @()
        }
    }

    $jsonStartIndex = -1
    for ($index = 0; $index -lt $outputLines.Count; $index++) {
        if ($outputLines[$index].TrimStart().StartsWith('{')) {
            $jsonStartIndex = $index
            break
        }
    }

    if ($jsonStartIndex -lt 0) {
        return @{
            Result = [pscustomobject]@{
                ok = $false
                auth = [pscustomobject]@{ ok = $false; message = 'Database helper returned invalid output.' }
                chars = [pscustomobject]@{ ok = $false; message = 'Database helper returned invalid output.' }
                import = [pscustomobject]@{ attempted = $false; ok = $false; message = 'Skipped' }
            }
            ExitCode = $exitCode
            PreludeLines = @($outputLines | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
            RawOutput = $outputLines
        }
    }

    $preludeLines = @()
    if ($jsonStartIndex -gt 0) {
        $preludeLines = @($outputLines[0..($jsonStartIndex - 1)] | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    }

    $jsonText = ($outputLines[$jsonStartIndex..($outputLines.Count - 1)] -join [Environment]::NewLine)
    try {
        $parsed = $jsonText | ConvertFrom-Json
        return @{ Result = $parsed; ExitCode = $exitCode; PreludeLines = $preludeLines; RawOutput = $outputLines }
    } catch {
        return @{
            Result = [pscustomobject]@{
                ok = $false
                auth = [pscustomobject]@{ ok = $false; message = 'Database helper JSON could not be parsed.' }
                chars = [pscustomobject]@{ ok = $false; message = 'Database helper JSON could not be parsed.' }
                import = [pscustomobject]@{ attempted = $false; ok = $false; message = 'Skipped' }
            }
            ExitCode = $exitCode
            PreludeLines = $preludeLines
            RawOutput = $outputLines
        }
    }
}

function Start-XamppApache {
    param([string]$Root)

    $apacheBatch = Join-Path $Root 'apache_start.bat'
    if (Test-Path -LiteralPath $apacheBatch) {
        Start-Process -FilePath $apacheBatch -WindowStyle Hidden
        return 'apache_start.bat'
    }

    $controlPanel = Join-Path $Root 'xampp-control.exe'
    if (Test-Path -LiteralPath $controlPanel) {
        Start-Process -FilePath $controlPanel
        return 'xampp-control.exe'
    }

    return $null
}

try {
    if (-not (Test-IsAdministrator)) {
        throw 'Run this installer from an elevated PowerShell session (Run as Administrator).'
    }

    Show-Banner
    Write-Step 'Checking prerequisites'
    Assert-CommandExists -CommandName 'winget'
    Show-IntroPanel

    if (-not (Read-YesNo 'Proceed with the installer?' $false)) {
        Write-WarnMessage 'Installation cancelled before any changes were made.'
        exit 0
    }

    $xamppPhp = Join-Path $XamppRoot 'php\php.exe'
    if ((Test-Path -LiteralPath $xamppPhp) -and -not $AllowExistingXampp) {
        Write-Box -Title 'Existing XAMPP Detected' -Lines @(
            "XAMPP already appears to be installed at '$XamppRoot'.",
            'You can stop now, or continue and reuse the existing XAMPP installation.',
            'If you continue, the installer will still deploy the website and update php.ini.'
        ) -BorderColor Yellow -TextColor White

        if (-not (Read-YesNo 'Reuse the existing XAMPP installation and continue?' $false)) {
            Write-WarnMessage 'Installation cancelled. No further changes were made.'
            exit 0
        }
    }

    if (-not (Test-Path -LiteralPath $xamppPhp)) {
        Write-Step 'Installing XAMPP with winget'
        & winget install --id ApacheFriends.Xampp.8.2 --accept-package-agreements --accept-source-agreements --disable-interactivity
        if ($LASTEXITCODE -ne 0) {
            throw 'winget failed to install XAMPP.'
        }
    }

    if (-not (Test-Path -LiteralPath $xamppPhp)) {
        throw "XAMPP was not found at '$XamppRoot' after installation."
    }

    Write-Step 'Downloading the prepared release package'
    $tempRoot = Join-Path ([IO.Path]::GetTempPath()) ("wow-legends-installer-{0}" -f [Guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $tempRoot
    $zipPath = Join-Path $tempRoot 'package.zip'
    $extractPath = Join-Path $tempRoot 'package'
    $packageSource = Get-ReleasePackageUrl -RepositoryOwner $Owner -RepositoryName $Repo -AssetName $ReleaseAssetName -ExplicitUrl $PackageUrl
    Download-File -Url $packageSource -DestinationPath $zipPath
    Expand-Archive -LiteralPath $zipPath -DestinationPath $extractPath -Force
    $contentRoot = Get-ExpandedContentRoot -ExtractPath $extractPath

    Write-Step 'Deploying application files into XAMPP htdocs'
    $backupPath = Backup-InstallRootIfNeeded -Path $InstallRoot
    if ($backupPath) {
        Write-Info "Existing htdocs content was backed up to '$backupPath'."
    }
    Copy-DirectoryContents -Source $contentRoot -Destination $InstallRoot

    Write-Step 'Enabling required PHP extensions'
    $phpIniPath = Join-Path $XamppRoot 'php\php.ini'
    if (-not (Test-Path -LiteralPath $phpIniPath)) {
        throw "php.ini was not found at '$phpIniPath'."
    }
    Enable-PHPExtensions -PhpIniPath $phpIniPath -Extensions @('pdo_mysql', 'openssl', 'mbstring', 'curl', 'fileinfo', 'gmp')

    Write-Step 'Creating a safe default config.php'
    $dbSettings = @{
        Host = Read-ValueWithDefault -Prompt 'Database host' -Default '127.0.0.1'
        Port = Read-ValueWithDefault -Prompt 'Database port' -Default '3306'
        User = Read-ValueWithDefault -Prompt 'Database user' -Default 'root'
        Password = Read-ValueWithDefault -Prompt 'Database password' -Default 'ascent' -Secret
        AuthDatabase = Read-ValueWithDefault -Prompt 'Auth database name' -Default 'auth'
        CharactersDatabase = Read-ValueWithDefault -Prompt 'Characters database name' -Default 'characters'
    }

    $templatePath = Join-Path $InstallRoot 'config.sample.php'
    $configPath = Join-Path $InstallRoot 'config.php'
    if (-not (Test-Path -LiteralPath $templatePath)) {
        throw 'config.sample.php was not found in the deployed package.'
    }
    New-InstallerConfig -TemplatePath $templatePath -ConfigPath $configPath -DbSettings $dbSettings -BaseUrl 'http://localhost'

    Write-Step 'Testing database connectivity'
    Write-Box -Title 'Database Check' -Lines @(
        'Make sure your repack database server is running before continuing.',
        'If you do not have a repack installed yet, get one first from https://www.emucoach.com/'
    ) -BorderColor Yellow -TextColor White
    $helperPath = Join-Path $tempRoot 'db-helper.php'
    $settingsPath = Join-Path $tempRoot 'db-settings.json'
    $sqlPath = Join-Path $InstallRoot 'sql\setup.sql'
    New-DatabaseHelperFile -Path $helperPath

    $shouldImport = $false
    if (Read-YesNo 'If the database connection works, import the support tables now?' $true) {
        $shouldImport = $true
    }

    $helperSettings = @{
        auth = @{
            host = $dbSettings.Host
            port = $dbSettings.Port
            user = $dbSettings.User
            password = $dbSettings.Password
            name = $dbSettings.AuthDatabase
        }
        chars = @{
            host = $dbSettings.Host
            port = $dbSettings.Port
            user = $dbSettings.User
            password = $dbSettings.Password
            name = $dbSettings.CharactersDatabase
        }
        import = $shouldImport
        sqlFile = $sqlPath
    }

    $helperSettings | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $settingsPath -Encoding ASCII
    $dbCheck = Invoke-DatabaseHelper -PhpExePath $xamppPhp -HelperPath $helperPath -SettingsPath $settingsPath

    foreach ($line in $dbCheck.PreludeLines) {
        Write-WarnMessage $line
    }

    if ($dbCheck.Result.auth.ok) {
        Write-Info 'Auth database connection succeeded.'
    } else {
        Write-WarnMessage ("Auth database connection failed: {0}" -f $dbCheck.Result.auth.message)
    }

    if ($dbCheck.Result.chars.ok) {
        Write-Info 'Characters database connection succeeded.'
    } else {
        Write-WarnMessage ("Characters database connection failed: {0}" -f $dbCheck.Result.chars.message)
    }

    if ($dbCheck.Result.import.attempted) {
        if ($dbCheck.Result.import.ok) {
            Write-Info 'Support tables were imported into the auth database.'
        } else {
            Write-WarnMessage ("Support table import failed: {0}" -f $dbCheck.Result.import.message)
        }
    } else {
        Write-Info 'Support table import was skipped.'
    }

    Write-Step 'Starting Apache'
    $apacheStartMethod = Start-XamppApache -Root $XamppRoot
    if ($null -eq $apacheStartMethod) {
        Write-WarnMessage 'Could not find an Apache start script. Open the XAMPP Control Panel and start Apache manually.'
    } else {
        Write-Info "Apache start was triggered using $apacheStartMethod."
    }

    if (-not $SkipBrowser) {
        Start-Process 'http://localhost/' | Out-Null
    }

    Write-Host ''
    Write-Box -Title 'Installation Complete' -Lines @(
        'WoW Legends was installed successfully.',
        "Location: $InstallRoot",
        'URL: http://localhost/',
        'Disabled by default: reCAPTCHA, password recovery, tickets',
        "Config file: $configPath",
        'To enable advanced features later, edit config.php and add the required keys/services.'
    ) -BorderColor Green -TextColor White
} catch {
    Write-Host ''
    Write-Box -Title 'Installer Error' -Lines @(
        'The installer hit an error and stopped.',
        $_.Exception.Message,
        'Fix the issue above and run the installer again.'
    ) -BorderColor Red -TextColor White
    exit 1
}