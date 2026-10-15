param(
    [Parameter(Mandatory = $true)]
    [string]$FullName,
    [Parameter(Mandatory = $true)]
    [string]$Username,
    [string]$PhpPath = "C:\xampp\php\php.exe"
)

if (-not (Test-Path $PhpPath)) {
    Write-Error "PHP was not found at '$PhpPath'. Supply the path to your XAMPP php.exe."
    exit 1
}

$securePassword = Read-Host "Choose an administrator password (12 or more characters)" -AsSecureString
$secureConfirmation = Read-Host "Confirm the administrator password" -AsSecureString
$password = [System.Net.NetworkCredential]::new("", $securePassword).Password
$confirmation = [System.Net.NetworkCredential]::new("", $secureConfirmation).Password

if ($password -ne $confirmation) {
    Write-Error "Passwords did not match. No account was created."
    exit 1
}
if ($password.Length -lt 12) {
    Write-Error "Use a password with at least 12 characters."
    exit 1
}

$env:COFFEE_ADMIN_PASSWORD = $password
try {
    & $PhpPath (Join-Path $PSScriptRoot "create_admin.php") $FullName $Username
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
finally {
    Remove-Item Env:COFFEE_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    $password = $null
    $confirmation = $null
}
