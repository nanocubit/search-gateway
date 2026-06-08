Add-Type -AssemblyName System.Drawing

$iconDir = "extension\icons"
if (-not (Test-Path $iconDir)) { New-Item -ItemType Directory -Path $iconDir -Force | Out-Null }

function Create-Icon($size, $path) {
    $bmp = New-Object System.Drawing.Bitmap($size, $size)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = 'AntiAlias'
    $g.TextRenderingHint = 'AntiAlias'

    $brush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(203, 166, 247))
    $g.FillRectangle($brush, 0, 0, $size, $size)

    $fontSize = [int]($size * 0.6)
    $font = New-Object System.Drawing.Font('Segoe UI', $fontSize, [System.Drawing.FontStyle]::Bold)
    $textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(30, 30, 46))
    $stringFormat = New-Object System.Drawing.StringFormat
    $stringFormat.Alignment = 'Center'
    $stringFormat.LineAlignment = 'Center'
    $g.DrawString('A', $font, $textBrush, (New-Object System.Drawing.RectangleF(0, 0, $size, $size)), $stringFormat)

    $g.Dispose()
    $bmp.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    Write-Host "  Created: $path (${size}x${size})" -ForegroundColor Green
}

Create-Icon 16 "$iconDir\icon16.png"
Create-Icon 48 "$iconDir\icon48.png"
Create-Icon 128 "$iconDir\icon128.png"

Write-Host ""
Write-Host "Icons generated successfully!" -ForegroundColor Cyan
