# ============================================================
# Free SSL Certificate Generator for codeandai.qd.je
# Uses ZeroSSL API (free tier: 3 certificates)
# ============================================================
#
# USAGE:
#   1. Sign up at https://zerossl.com (free, no credit card)
#   2. Get your API key from: https://zerossl.com/developer/api
#   3. Run this script: .\generate-ssl.ps1
#
# ============================================================

param(
    [string]$Domain = "codeandai.qd.je",
    [string]$ApiKey = "",
    [string]$CsrFile = "",
    [string]$OutputDir = ".\ssl-certs"
)

# ── Colors ────────────────────────────────────────────────────
function Write-Step($msg)  { Write-Host "`n>> $msg" -ForegroundColor Cyan }
function Write-OK($msg)    { Write-Host "   [OK] $msg" -ForegroundColor Green }
function Write-Fail($msg)  { Write-Host "   [FAIL] $msg" -ForegroundColor Red }
function Write-Info($msg)  { Write-Host "   $msg" -ForegroundColor Gray }

# ── Banner ────────────────────────────────────────────────────
Write-Host ""
Write-Host "=============================================" -ForegroundColor Yellow
Write-Host "  Free SSL Certificate Generator" -ForegroundColor Yellow
Write-Host "  Domain: $Domain" -ForegroundColor Yellow
Write-Host "=============================================" -ForegroundColor Yellow

# ── Step 0: Check API Key ────────────────────────────────────
Write-Step "Step 0: Check API Key"

if (-not $ApiKey) {
    Write-Host ""
    Write-Host "   You need a ZeroSSL API key. Here's how to get one:" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "   1. Go to: https://zerossl.com/sign-up" -ForegroundColor White
    Write-Host "   2. Create a FREE account (no credit card)" -ForegroundColor White
    Write-Host "   3. Go to: https://zerossl.com/developer/api" -ForegroundColor White
    Write-Host "   4. Copy your API key" -ForegroundColor White
    Write-Host ""
    $ApiKey = Read-Host "   Paste your ZeroSSL API key here"
    
    if (-not $ApiKey) {
        Write-Fail "API key is required. Exiting."
        exit 1
    }
}
Write-OK "API key provided"

# ── Step 1: Generate CSR if needed ───────────────────────────
Write-Step "Step 1: Generate CSR and Private Key"

if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$csrPath = Join-Path $OutputDir "domain.csr"
$keyPath = Join-Path $OutputDir "private.key"
$pemPath = Join-Path $OutputDir "domain.pem"

if ($CsrFile -and (Test-Path $CsrFile)) {
    Write-Info "Using existing CSR: $CsrFile"
    Copy-Item $CsrFile $csrPath -Force
} else {
    Write-Info "Generating new CSR and private key..."
    
    # Generate private key and CSR using OpenSSL
    $openssl = $null
    @("openssl", "C:\Program Files\OpenSSL\bin\openssl.exe", "C:\OpenSSL\bin\openssl.exe") | ForEach-Object {
        if (-not $openssl -and (Get-Command $_ -ErrorAction SilentlyContinue)) {
            $openssl = $_
        }
    }
    
    if ($openssl) {
        Write-Info "Using OpenSSL: $openssl"
        
        # Generate private key
        & $openssl genrsa -out $keyPath 2048 2>$null
        
        # Generate CSR
        $subject = "/CN=$Domain/O=Freebuff/C=US"
        & $openssl req -new -key $keyPath -out $csrPath -subj $subject 2>$null
        
        Write-OK "CSR and private key generated"
    } else {
        Write-Info "OpenSSL not found. Using PowerShell to generate..."
        
        # Generate CSR using PowerShell
        $cert = New-SelfSignedCertificate `
            -DnsName $Domain `
            -CertStoreLocation "Cert:\CurrentUser\My" `
            -KeyLength 2048 `
            -KeyAlgorithm RSA `
            -HashAlgorithm SHA256 `
            -NotAfter (Get-Date).AddYears(1)
        
        # Export CSR
        $csrContent = @"
-----BEGIN CERTIFICATE REQUEST-----
$([Convert]::ToBase64String($cert.CSR.RawData, [System.Base64FormattingOptions]::InsertLineBreaks))
-----END CERTIFICATE REQUEST-----
"@
        Set-Content -Path $csrPath -Value $csrContent
        
        Write-OK "CSR generated using PowerShell"
        Write-Info "CSR saved to: $csrPath"
    }
}

# Read the CSR content
$csrContent = (Get-Content $csrPath -Raw) -replace "`r`n", "`n" -replace "`n", "\n"
Write-OK "CSR loaded"

# ── Step 2: Create Certificate with ZeroSSL ──────────────────
Write-Step "Step 2: Submit CSR to ZeroSSL API"

$apiUrl = "https://api.zerossl.com/certificates?access_key=$ApiKey"

$body = @{
    certificate_domains = $Domain
    certificate_validity_days = 90
    certificate_csr = (Get-Content $csrPath -Raw)
} | ConvertTo-Json

try {
    $response = Invoke-RestMethod -Uri $apiUrl -Method POST -Body $body -ContentType "application/json"
    $certId = $response.id
    Write-OK "Certificate request created! ID: $certId"
} catch {
    Write-Fail "Failed to create certificate request"
    Write-Info "Error: $($_.Exception.Message)"
    
    # Try to read the response body
    if ($_.Exception.Response) {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $errorBody = $reader.ReadToEnd()
        Write-Info "Response: $errorBody"
    }
    exit 1
}

# ── Step 3: Get Verification Methods ─────────────────────────
Write-Step "Step 3: Get verification methods"

$verifyUrl = "https://api.zerossl.com/certificates/$certId/challenges?access_key=$ApiKey"

try {
    $verifyResponse = Invoke-RestMethod -Uri $verifyUrl -Method GET
    Write-OK "Verification methods retrieved"
    
    # Show available methods
    Write-Host ""
    Write-Host "   Available verification methods:" -ForegroundColor Yellow
    Write-Host ""
    
    $methods = @()
    if ($verifyResponse.file_validation_http_link) {
        Write-Host "   [1] HTTP File Upload (Recommended for cPanel)" -ForegroundColor White
        Write-Info "       Upload a file to your website"
        $methods += "http"
    }
    if ($verifyResponse.file_validation_dns_link) {
        Write-Host "   [2] DNS Record" -ForegroundColor White
        Write-Info "       Add a TXT record to your DNS"
        $methods += "dns"
    }
    Write-Host ""
    
} catch {
    Write-Fail "Failed to get verification methods"
    Write-Info "Error: $($_.Exception.Message)"
    exit 1
}

# ── Step 4: Show Verification Instructions ───────────────────
Write-Step "Step 4: Domain Verification Instructions"

Write-Host ""
Write-Host "   ============================================" -ForegroundColor Yellow
Write-Host "   OPTION 1: HTTP File Upload (Easiest)" -ForegroundColor Yellow
Write-Host "   ============================================" -ForegroundColor Yellow
Write-Host ""

if ($verifyResponse.file_validation_http_link) {
    $httpFile = $verifyResponse.file_validation_http_link
    $httpFileContent = $verifyResponse.file_validation_http_file_content
    $httpToken = $verifyResponse.file_validation_http_token
    
    Write-Host "   1. Create this file on your server:" -ForegroundColor White
    Write-Host "      Path: http://$Domain/$httpToken" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "   2. File location in cPanel File Manager:" -ForegroundColor White
    Write-Host "      public_html/$httpToken" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "   3. The file content is provided by ZeroSSL." -ForegroundColor White
    Write-Host "      ZeroSSL will automatically check this file." -ForegroundColor White
    Write-Host ""
    
    # Save the token file locally
    $tokenDir = Join-Path $OutputDir "verification"
    if (-not (Test-Path $tokenDir)) {
        New-Item -ItemType Directory -Path $tokenDir -Force | Out-Null
    }
    
    if ($httpToken) {
        $tokenPath = Join-Path $tokenDir $httpToken
        Set-Content -Path $tokenPath -Value $httpToken
        Write-Info "Token file saved to: $tokenPath"
    }
}

Write-Host ""
Write-Host "   ============================================" -ForegroundColor Yellow
Write-Host "   OPTION 2: DNS Record" -ForegroundColor Yellow
Write-Host "   ============================================" -ForegroundColor Yellow
Write-Host ""

if ($verifyResponse.file_validation_dns_link) {
    Write-Host "   Add this TXT record in your DNS:" -ForegroundColor White
    Write-Host "      Name: _dnsauth.$Domain" -ForegroundColor Cyan
    Write-Host "      Value: (check ZeroSSL dashboard)" -ForegroundColor Cyan
    Write-Host ""
}

Write-Host ""
Write-Host "   ============================================" -ForegroundColor Yellow
Write-Host "   After completing verification:" -ForegroundColor Yellow
Write-Host "   ============================================" -ForegroundColor Yellow
Write-Host ""
Write-Host "   1. Go to https://zerossl.com" -ForegroundColor White
Write-Host "   2. Find your certificate" -ForegroundColor White
Write-Host "   3. Click 'Download' to get:" -ForegroundColor White
Write-Host "      - certificate.crt (your SSL certificate)" -ForegroundColor White
Write-Host "      - ca_bundle.crt (certificate chain)" -ForegroundColor White
Write-Host "      - private.key (your private key)" -ForegroundColor White
Write-Host ""
Write-Host "   4. Upload all 3 files to your cPanel:" -ForegroundColor White
Write-Host "      - Go to 'SSL/TLS' in cPanel" -ForegroundColor White
 paste the certificate, CA bundle, and private key
Write-Host ""

# ── Step 5: Wait for verification ────────────────────────────
Write-Step "Step 5: Waiting for domain verification..."
Write-Host ""
Write-Host "   Follow the verification steps above, then press Enter when done." -ForegroundColor Yellow
Read-Host "   Press Enter to continue"

# ── Step 6: Check certificate status ─────────────────────────
Write-Step "Step 6: Check certificate status"

$certUrl = "https://api.zerossl.com/certificates/$certId?access_key=$ApiKey"

try {
    $certStatus = Invoke-RestMethod -Uri $certUrl -Method GET
    
    if ($certStatus.status -eq "issued") {
        Write-OK "Certificate has been issued!"
        
        # Download certificate files
        $certPem = $certStatus.certificate
        $caBundle = $certStatus.ca_bundle
        
        # Save certificate
        $certPath = Join-Path $OutputDir "certificate.crt"
        Set-Content -Path $certPath -Value $certPem
        Write-OK "Certificate saved to: $certPath"
        
        # Save CA bundle
        $caPath = Join-Path $OutputDir "ca_bundle.crt"
        Set-Content -Path $caPath -Value $caBundle
        Write-OK "CA bundle saved to: $caPath"
        
        # Save private key (if we generated it)
        if (Test-Path $keyPath) {
            Write-OK "Private key: $keyPath"
        } else {
            Write-Info "Private key: Check your CSR generation source"
        }
        
        Write-Host ""
        Write-Host "   ============================================" -ForegroundColor Green
        Write-Host "   SUCCESS! Your SSL certificate is ready!" -ForegroundColor Green
        Write-Host "   ============================================" -ForegroundColor Green
        Write-Host ""
        Write-Host "   Files saved to: $OutputDir" -ForegroundColor White
        Write-Host ""
        Write-Host "   Upload these to cPanel > SSL/TLS:" -ForegroundColor White
        Write-Host "      - certificate.crt" -ForegroundColor Cyan
        Write-Host "      - ca_bundle.crt" -ForegroundColor Cyan
        Write-Host "      - private.key" -ForegroundColor Cyan
        Write-Host ""
        
    } elseif ($certStatus.status -eq "pending_validation") {
        Write-Info "Certificate is still pending validation."
        Write-Info "Make sure you completed the verification step."
        Write-Info "Status: $($certStatus.status)"
        
    } else {
        Write-Fail "Certificate status: $($certStatus.status)"
        Write-Info "Check ZeroSSL dashboard for details."
    }
    
} catch {
    Write-Fail "Failed to check certificate status"
    Write-Info "Error: $($_.Exception.Message)"
    Write-Info "Check your certificate manually at https://zerossl.com"
}

Write-Host ""
Write-Host "=============================================" -ForegroundColor Yellow
Write-Host "  Done!" -ForegroundColor Yellow
Write-Host "=============================================" -ForegroundColor Yellow
