$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot   # project root (script lives in tools/)
$out = Join-Path $root 'deployment-2026-09-13.zip'
if (Test-Path $out) { Remove-Item $out }

$files = @(
  # Docs
  'README.md', 'DEPLOYMENT.md', '.env.example',
  # Root app files
  '.htaccess', 'api_demo_classes.php', 'config.php', 'dashboard.php', 'download.php',
  'health.php', 'index.php', 'install.php', 'login.php', 'logout.php', 'payment.php',
  'register.php', 'resources.php', 'robots.txt', 'sitemap.xml', 'success.php',
  'trainers.php', 'training-calendar.php',
  # Assets
  'assets/css/style.css', 'assets/js/organizer.js', 'assets/js/resources.js',
  # includes
  'includes/admin_dashboard_service.php', 'includes/class_management_service.php',
  'includes/dashboard_service.php', 'includes/db.php', 'includes/email_service.php',
  'includes/functions.php', 'includes/registration_service.php',
  'includes/security_headers.php', 'includes/training_resource_service.php',
  'includes/upi_service.php', 'includes/lib/qr_encoder.php',
  # organizer
  'organizer/api_class_status.php', 'organizer/api_delete.php', 'organizer/api_demo_classes.php',
  'organizer/api_follow_up.php', 'organizer/api_payment_reconcile.php',
  'organizer/api_payment_status.php', 'organizer/api_registrations.php',
  'organizer/api_training_resources.php', 'organizer/dashboard.php',
  'organizer/export_csv.php', 'organizer/upi-callback.php',
  # whatsapp
  'whatsapp/redirect.php', 'whatsapp/status.php'
)

$missing = @()
foreach ($f in $files) {
  if (-not (Test-Path (Join-Path $root $f) -PathType Leaf)) { $missing += $f }
}
if ($missing.Count -gt 0) { Write-Output ("MISSING: " + ($missing -join ', ')); exit 1 }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($out, 'Create')
try {
  foreach ($f in $files) {
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $root $f), ($f -replace '\\','/')) | Out-Null
  }
} finally {
  $zip.Dispose()
}
$size = [math]::Round((Get-Item $out).Length / 1KB)
Write-Output ("Created deployment-2026-09-13.zip with " + $files.Count + " files, " + $size + " KB")
