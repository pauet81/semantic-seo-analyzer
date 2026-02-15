# Script de despliegue FTP (sin credenciales hardcodeadas).
# Uso (PowerShell):
#   $env:FTP_SERVER="ftp.tu-dominio.com"
#   $env:FTP_USER="usuario"
#   $env:FTP_PASS="password"
#   pwsh -File .\deploy.ps1
#
# Opcional:
#   $env:LOCAL_PATH="C:\ruta\al\proyecto"  (por defecto: carpeta actual)
#   $env:REMOTE_BASE="ruta/remota/base"    (por defecto: vacio)

$ftpServer = $env:FTP_SERVER
$ftpUser = $env:FTP_USER
$ftpPass = $env:FTP_PASS
$localPath = if ($env:LOCAL_PATH) { $env:LOCAL_PATH } else { (Get-Location).Path }
$remoteBase = if ($env:REMOTE_BASE) { $env:REMOTE_BASE.TrimEnd('/') } else { '' }

if ([string]::IsNullOrWhiteSpace($ftpServer) -or [string]::IsNullOrWhiteSpace($ftpUser) -or [string]::IsNullOrWhiteSpace($ftpPass)) {
    throw "Faltan variables de entorno. Define FTP_SERVER, FTP_USER y FTP_PASS."
}

# Crear sesión FTP
$ftpCredential = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)

function Upload-FileToFTP {
    param($localFile, $remotePath)
    $remote = if ($remoteBase) { "$remoteBase/$remotePath" } else { $remotePath }
    $ftpUri = "ftp://$ftpServer/$remote"
    $ftpRequest = [System.Net.FtpWebRequest]::Create($ftpUri)
    $ftpRequest.Credentials = $ftpCredential
    $ftpRequest.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    
    $fileContent = [System.IO.File]::ReadAllBytes($localFile)
    $ftpRequest.ContentLength = $fileContent.Length
    
    $stream = $ftpRequest.GetRequestStream()
    $stream.Write($fileContent, 0, $fileContent.Length)
    $stream.Close()
    
    $response = $ftpRequest.GetResponse()
    Write-Host "Subido: $remotePath"
    $response.Close()
}

# Archivos a subir
$files = @(
    "api/analyze.php",
    "api/generate-content.php",
    "api/adjust-content.php",
    "api/optimize-content.php",
    "config.default.php",
    "config.local.php.example",
    "config.php",
    "js/app.js",
    "css/styles.css",
    "index.html"
)

foreach ($file in $files) {
    $localFile = Join-Path $localPath $file
    if (Test-Path $localFile) {
        try {
            Upload-FileToFTP $localFile $file
            Write-Host "[OK] $file"
        }
        catch {
            Write-Host "[ERROR] en $file : $_"
        }
    }
    else {
        Write-Host "[NOT FOUND] $file"
    }
}

Write-Host "Deploy completado"
