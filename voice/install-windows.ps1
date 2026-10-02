# Bank assistant - local Persian voice (STT + TTS) on a Windows PC, for testing.
# Everything runs on this computer: faster-whisper (speech to text) and Piper
# (text to speech). Internet is needed only during this install.
#
# From the bank-assistant folder, in PowerShell:
#   powershell -ExecutionPolicy Bypass -File voice\install-windows.ps1
#
# Options:
#   -SttModel large-v3-turbo   (default; 'small' for a weak PC, 'large-v3' for best)
#   -TtsVoice fa_IR-gyro-medium
#   -Port 8765
# Afterwards start-windows.bat starts the voice service by itself.

param(
    [string]$SttModel = "large-v3-turbo",
    [string]$TtsVoice = "fa_IR-gyro-medium",
    [int]$Port = 8765
)

# Native tools (pip, py) write notices to stderr; with "Stop" Windows PowerShell 5.1
# would treat that as a failure. Exit codes are checked instead.
$ErrorActionPreference = "Continue"
$Voice = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Voice
$Venv = Join-Path $Voice "venv"
$Py = Join-Path $Venv "Scripts\python.exe"
$Models = Join-Path $Voice "models"
$EnvFile = Join-Path $Voice "voice.env"
$Config = Join-Path $Root "server\config.php"

function Say($t) { Write-Host ""; Write-Host "==> $t" -ForegroundColor Green }
function Warn($t) { Write-Host "!! $t" -ForegroundColor Yellow }
function Die($t) { Write-Host ""; Write-Host "ERROR: $t" -ForegroundColor Red; exit 1 }
function Have($cmd) { return [bool](Get-Command $cmd -ErrorAction SilentlyContinue) }

# ------------------------------------------------------------- 1. Python
Say "Looking for Python 3.11 - 3.13"
$PyCmd = $null
if (Have "py") {
    foreach ($v in @("3.12", "3.11", "3.13")) {
        $out = & py "-$v" -c "import sys; print(sys.version)" 2>&1
        if ($LASTEXITCODE -eq 0) { $PyCmd = @("py", "-$v"); break }
    }
}
if (-not $PyCmd -and (Have "python")) {
    $out = & python -c "import sys; print('%d.%d' % sys.version_info[:2])" 2>&1
    if ($LASTEXITCODE -eq 0 -and @("3.11", "3.12", "3.13") -contains "$out".Trim()) { $PyCmd = @("python") }
}
if (-not $PyCmd) {
    if (Have "winget") {
        Warn "Python 3.12 not found. Installing it with winget..."
        winget install --id Python.Python.3.12 -e --accept-source-agreements --accept-package-agreements
        Die "Python was installed. Close this PowerShell window, open a new one and run the same command again."
    }
    Die "Install Python 3.12 from https://www.python.org/downloads/ (tick 'Add python.exe to PATH'), then run this again."
}
Write-Host ("using: " + ($PyCmd -join " "))

# ------------------------------------------------------------- 2. ffmpeg
Say "Looking for ffmpeg"
function Find-Ffmpeg {
    $c = Get-Command ffmpeg -ErrorAction SilentlyContinue
    if ($c) { return $c.Source }
    $roots = @("$env:LOCALAPPDATA\Microsoft\WinGet\Packages", "$env:ProgramFiles\ffmpeg", "C:\ffmpeg")
    foreach ($r in $roots) {
        if (Test-Path $r) {
            $f = Get-ChildItem -Path $r -Filter ffmpeg.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($f) { return $f.FullName }
        }
    }
    return $null
}
$Ffmpeg = Find-Ffmpeg
if (-not $Ffmpeg) {
    Warn "ffmpeg not found. Installing it with winget..."
    if (Have "winget") { winget install --id Gyan.FFmpeg -e --accept-source-agreements --accept-package-agreements }
    $Ffmpeg = Find-Ffmpeg
}
if (-not $Ffmpeg) {
    Die "ffmpeg missing. Download the 'release essentials' zip from https://www.gyan.dev/ffmpeg/builds/, extract it to C:\ffmpeg and run this again."
}
Write-Host "using: $Ffmpeg"

# ------------------------------------------------------- 3. venv + packages
Say "Python packages (faster-whisper, piper-tts) in voice\venv - a few minutes"
if (-not (Test-Path $Py)) {
    $a = @(); if ($PyCmd.Count -gt 1) { $a = $PyCmd[1..($PyCmd.Count - 1)] }
    & $PyCmd[0] @a -m venv $Venv
    if ($LASTEXITCODE -ne 0) { Die "could not create the venv" }
}
& $Py -m pip install -q --upgrade pip wheel
& $Py -m pip install -q -r (Join-Path $Voice "requirements.txt")
if ($LASTEXITCODE -ne 0) { Die "pip install failed (internet / proxy?)" }

& $Py -c "import ctranslate2, onnxruntime, av, piper" 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) {
    Warn "Engines need the Microsoft Visual C++ runtime. Installing it..."
    if (Have "winget") { winget install --id Microsoft.VCRedist.2015+.x64 -e --accept-source-agreements --accept-package-agreements }
    & $Py -c "import ctranslate2, onnxruntime, av, piper"
    if ($LASTEXITCODE -ne 0) { Die "Install https://aka.ms/vs/17/release/vc_redist.x64.exe and run this again." }
}

# ------------------------------------------------------------ 4. settings
$Token = $null
if (Test-Path $EnvFile) {
    $line = Select-String -Path $EnvFile -Pattern '^VOICE_TOKEN=(.+)$' | Select-Object -First 1
    if ($line) { $Token = $line.Matches[0].Groups[1].Value.Trim() }
}
if (-not $Token) {
    $b = New-Object byte[] 24
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    $Token = -join ($b | ForEach-Object { $_.ToString("x2") })
}
$envText = @"
# Local voice service settings (read by voice_service.py). See voice.env.sample.
VOICE_HOST=127.0.0.1
VOICE_PORT=$Port
VOICE_TOKEN=$Token
VOICE_MODELS_DIR=$Models
FFMPEG=$Ffmpeg
STT_MODEL=$SttModel
STT_DEVICE=cpu
STT_COMPUTE_TYPE=int8
STT_THREADS=0
STT_BEAM_SIZE=5
TTS_VOICE=$TtsVoice
TTS_LENGTH_SCALE=1.0
VOICE_PRELOAD=1
"@
$utf8 = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($EnvFile, $envText, $utf8)

# -------------------------------------------------------------- 5. models
Say "Downloading the models once: $SttModel + $TtsVoice (about 1-2 GB)"
& $Py (Join-Path $Voice "voice_service.py") download --models-dir $Models --stt-model $SttModel --tts-voice $TtsVoice
if ($LASTEXITCODE -ne 0) { Die "model download failed (is huggingface.co reachable? try a VPN, or copy a 'models' folder from another PC)" }

Say "Self-test (no models needed)"
& $Py (Join-Path $Voice "test_voice_service.py") 2>&1 | Out-Null
if ($LASTEXITCODE -eq 0) { Write-Host "ok" } else { Warn "self-test failed: run  voice\venv\Scripts\python.exe voice\test_voice_service.py" }

# ---------------------------------------------------------- 6. config.php
if (-not (Test-Path $Config)) {
    Warn "server\config.php does not exist yet: run start-windows.bat once (it creates it), close it, then run this script again."
    exit 0
}
Say "Writing voice_url / voice_token into server\config.php"
Copy-Item $Config "$Config.bak" -Force
$lines = [System.IO.File]::ReadAllLines($Config, $utf8) |
    Where-Object { $_ -notmatch "^\s*'(voice_url|voice_token|voice_cli|stt_url|stt_key|stt_model|tts_url|tts_key|tts_model|tts_voice)'\s*=>" }
$out = New-Object System.Collections.Generic.List[string]
$done = $false
foreach ($l in $lines) {
    $out.Add($l)
    if (-not $done -and $l -match '^return \[') {
        $out.Add("    'voice_url' => 'http://127.0.0.1:$Port',")
        $out.Add("    'voice_token' => '$Token',")
        $out.Add("    'voice_cli' => '',")
        $done = $true
    }
}
if (-not $done) { Die "could not find 'return [' in config.php; add voice_url / voice_token by hand (token in voice\voice.env)" }
# UTF-8 without BOM: a BOM before <?php would break every API response.
[System.IO.File]::WriteAllText($Config, (($out -join "`r`n") + "`r`n"), $utf8)

Say "Done."
Write-Host "Now run start-windows.bat - a 'voice' window starts too (first start loads the models, ~30 s)."
Write-Host "Test:  .\start-windows.bat voice-test   (Persian sentence -> speech -> text)"
