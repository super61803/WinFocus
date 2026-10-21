# End-to-end test: drives the real WinFocus.exe and checks its behaviour via Win32.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\e2e.ps1 [-Exe path] [-Screenshot path]
param(
    [string]$Exe = "$PSScriptRoot\..\target\release\WinFocus.exe",
    [string]$Screenshot = "$env:TEMP\winfocus-e2e.png"
)
$ErrorActionPreference = 'Stop'

Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class W {
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int L, T, R, B; }
    [DllImport("user32.dll")] public static extern bool SetProcessDPIAware();
    [DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern IntPtr FindWindow(string c, string t);
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr h);
    [DllImport("user32.dll")] public static extern IntPtr GetWindow(IntPtr h, uint cmd);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr h);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr h, out RECT r);
    [DllImport("user32.dll")] public static extern int GetWindowLong(IntPtr h, int i);
    [DllImport("user32.dll")] public static extern bool GetLayeredWindowAttributes(IntPtr h, out uint key, out byte alpha, out uint flags);
    [DllImport("user32.dll")] public static extern int GetSystemMetrics(int i);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr h, uint m, IntPtr w, IntPtr l);
    [DllImport("user32.dll")] public static extern void keybd_event(byte vk, byte scan, uint flags, IntPtr extra);
    [DllImport("user32.dll")] public static extern IntPtr WindowFromPoint(System.Drawing.Point p);
    [DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern int GetClassName(IntPtr h, StringBuilder sb, int n);
    [DllImport("user32.dll")] public static extern bool MoveWindow(IntPtr h, int x, int y, int w, int hgt, bool repaint);
    [DllImport("user32.dll")] static extern IntPtr GetDC(IntPtr h);
    [DllImport("user32.dll")] static extern int ReleaseDC(IntPtr h, IntPtr dc);
    [DllImport("gdi32.dll")] static extern bool BitBlt(IntPtr d, int x, int y, int w, int h, IntPtr s, int sx, int sy, uint rop);
    // SRCCOPY | CAPTUREBLT so layered windows (the overlay) are included.
    public static System.Drawing.Bitmap Capture(int x, int y, int w, int h) {
        var bmp = new System.Drawing.Bitmap(w, h);
        using (var g = System.Drawing.Graphics.FromImage(bmp)) {
            IntPtr dst = g.GetHdc(), src = GetDC(IntPtr.Zero);
            BitBlt(dst, 0, 0, w, h, src, x, y, 0x00CC0020 | 0x40000000);
            ReleaseDC(IntPtr.Zero, src); g.ReleaseHdc(dst);
        }
        return bmp;
    }
    public static IntPtr ByClass(string c) { return FindWindow(c, null); }
    public static IntPtr ByTitle(string t) { return FindWindow(null, t); }
    public static string Cls(IntPtr h) { var sb = new StringBuilder(256); GetClassName(h, sb, 256); return sb.ToString(); }
    public static void Keys(params byte[] vks) {
        foreach (var k in vks) keybd_event(k, 0, 0, IntPtr.Zero);
        for (int i = vks.Length - 1; i >= 0; i--) keybd_event(vks[i], 0, 2, IntPtr.Zero);
    }
    public static bool Focus(IntPtr h) { keybd_event(0x12, 0, 0, IntPtr.Zero); keybd_event(0x12, 0, 2, IntPtr.Zero); return SetForegroundWindow(h); }
}
'@
[W]::SetProcessDPIAware() | Out-Null

$script:fails = 0
function Check($name, [bool]$ok, $detail = '') {
    if ($ok) { Write-Host "PASS  $name" -ForegroundColor Green }
    else { Write-Host "FAIL  $name  $detail" -ForegroundColor Red; $script:fails++ }
}
function WaitFor([scriptblock]$cond, [int]$ms = 3000) {
    $sw = [Diagnostics.Stopwatch]::StartNew()
    while ($sw.ElapsedMilliseconds -lt $ms) { if (& $cond) { return $true }; Start-Sleep -Milliseconds 50 }
    return [bool](& $cond)
}
function Alpha($h) { $k = 0; $a = [byte]0; $f = 0; [W]::GetLayeredWindowAttributes($h, [ref]$k, [ref]$a, [ref]$f) | Out-Null; $a }
function RegVal($name) { (Get-ItemProperty HKCU:\Software\WinFocus -ErrorAction SilentlyContinue).$name }
function BelowFg($ov) {
    if (-not [W]::IsWindowVisible($ov)) { return $false }
    $h = [W]::GetWindow([W]::GetForegroundWindow(), 2)
    while ($h -ne [IntPtr]::Zero -and $h -ne $ov -and -not [W]::IsWindowVisible($h)) { $h = [W]::GetWindow($h, 2) }
    $h -eq $ov
}
$runKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
function Autostart { (Get-ItemProperty $runKey -ErrorAction SilentlyContinue).WinFocus }

$VK_CTRL = 0x11; $VK_ALT = 0x12; $VK_F = 0x46; $VK_UP = 0x26; $VK_DOWN = 0x28; $VK_RIGHT = 0x27; $VK_RET = 0x0D
$WM_TRAY = 0x8001; $WM_LBUTTONUP = 0x0202; $WM_RBUTTONUP = 0x0205

Get-Process WinFocus -ErrorAction SilentlyContinue | Stop-Process -Force
Remove-Item HKCU:\Software\WinFocus -Recurse -ErrorAction SilentlyContinue
$helpers = @()

try {
    $app = Start-Process $Exe -PassThru
    $ov = [IntPtr]::Zero
    WaitFor { $script:ov = [W]::ByClass('WinFocusOverlay'); $script:ov -ne [IntPtr]::Zero } | Out-Null
    $ctrl = [W]::ByClass('WinFocusController')
    Check 'overlay window created' ($ov -ne [IntPtr]::Zero)
    Check 'controller window created' ($ctrl -ne [IntPtr]::Zero)

    $ex = [W]::GetWindowLong($ov, -20)
    $need = 0x80000 -bor 0x20 -bor 0x80 -bor 0x8000000   # LAYERED|TRANSPARENT|TOOLWINDOW|NOACTIVATE
    Check 'overlay is layered, click-through, no-activate' (($ex -band $need) -eq $need) ('exstyle=0x{0:X}' -f $ex)
    Check 'default dim 60% (alpha 153)' ((Alpha $ov) -eq 153) "alpha=$(Alpha $ov)"

    $r = New-Object W+RECT; [W]::GetWindowRect($ov, [ref]$r) | Out-Null
    $vx = [W]::GetSystemMetrics(76); $vy = [W]::GetSystemMetrics(77); $vw = [W]::GetSystemMetrics(78); $vh = [W]::GetSystemMetrics(79)
    Check 'overlay covers whole virtual screen' ($r.L -eq $vx -and $r.T -eq $vy -and ($r.R - $r.L) -eq $vw -and ($r.B - $r.T) -eq $vh) "rect=$($r.L),$($r.T),$($r.R),$($r.B) vs $vx,$vy,$vw,$vh"

    # Two helper windows to switch focus between.
    $a = Start-Process charmap -PassThru; $helpers += $a
    $winA = [IntPtr]::Zero
    WaitFor { $a.Refresh(); $script:winA = $a.MainWindowHandle; $script:winA -ne [IntPtr]::Zero } 5000 | Out-Null
    [W]::Focus($winA) | Out-Null
    Check 'overlay sits directly beneath focused window A' (WaitFor { ([W]::GetForegroundWindow() -eq $winA) -and (BelowFg $ov) })

    # Click-through: the point at the screen centre should hit something other than the overlay.
    $hit = [W]::WindowFromPoint((New-Object System.Drawing.Point(($vx + 5), ($vy + 5))))
    Check 'mouse hit-testing passes through overlay' ($hit -ne $ov) "hit=$([W]::Cls($hit))"

    $b = Start-Process charmap -PassThru; $helpers += $b
    $winB = [IntPtr]::Zero
    WaitFor { $b.Refresh(); $script:winB = $b.MainWindowHandle; $script:winB -ne [IntPtr]::Zero } 5000 | Out-Null
    [W]::MoveWindow($winB, 500, 250, 700, 500, $true) | Out-Null
    [W]::Focus($winB) | Out-Null
    Check 'overlay follows focus to window B' (WaitFor { ([W]::GetForegroundWindow() -eq $winB) -and (BelowFg $ov) }) "fg=$([W]::Cls([W]::GetForegroundWindow()))"
    [W]::Focus($winA) | Out-Null
    Check 'overlay follows focus back to window A' (WaitFor { ([W]::GetForegroundWindow() -eq $winA) -and (BelowFg $ov) })

    # Latency: time from focus switch until the overlay sits beneath the new window.
    $lat = foreach ($i in 1..20) {
        $target = if ($i % 2) { $winB } else { $winA }
        [W]::Focus($target) | Out-Null
        $sw = [Diagnostics.Stopwatch]::StartNew()
        while (-not (([W]::GetForegroundWindow() -eq $target) -and (BelowFg $ov)) -and $sw.ElapsedMilliseconds -lt 2000) { }
        $sw.Elapsed.TotalMilliseconds
        Start-Sleep -Milliseconds 100
    }
    $m = $lat | Measure-Object -Average -Maximum
    Write-Host ("      focus-switch latency: avg {0:N1} ms, max {1:N1} ms" -f $m.Average, $m.Maximum)
    Check 'focus switch reacts within 50 ms' ($m.Maximum -lt 50)

    # Opening a new window: the dimming must not drop out while it appears.
    $c = Start-Process charmap -PassThru; $helpers += $c
    $sw = [Diagnostics.Stopwatch]::StartNew(); $flicker = $false; $winC = [IntPtr]::Zero; $tFg = $null
    while ($sw.ElapsedMilliseconds -lt 5000) {
        if (-not [W]::IsWindowVisible($ov)) { $flicker = $true }
        if ($winC -eq [IntPtr]::Zero) { $c.Refresh(); $winC = $c.MainWindowHandle }
        if ($winC -ne [IntPtr]::Zero -and [W]::GetForegroundWindow() -eq $winC) {
            if ($null -eq $tFg) { $tFg = $sw.Elapsed.TotalMilliseconds }
            if (BelowFg $ov) { break }
        }
    }
    $openLat = if ($null -ne $tFg) { $sw.Elapsed.TotalMilliseconds - $tFg } else { -1 }
    Write-Host ("      new window: overlay beneath it {0:N1} ms after it took focus" -f $openLat)
    Check 'no undimmed flash while a new window opens' (-not $flicker)
    Check 'new window highlighted within 50 ms' ($openLat -ge 0 -and $openLat -lt 50)
    $c | Stop-Process -Force; [W]::Focus($winA) | Out-Null; Start-Sleep -Milliseconds 300

    $app.Refresh(); $cpu0 = $app.TotalProcessorTime; Start-Sleep -Seconds 3; $app.Refresh()
    $idleCpu = ($app.TotalProcessorTime - $cpu0).TotalMilliseconds
    Write-Host ("      idle CPU over 3 s: {0:N1} ms, working set: {1:N1} MB, private: {2:N1} MB" -f $idleCpu, ($app.WorkingSet64 / 1MB), ($app.PrivateMemorySize64 / 1MB))
    Check 'idle CPU usage is negligible' ($idleCpu -lt 20)
    [W]::Focus($winA) | Out-Null

    Start-Sleep -Milliseconds 300
    $bmp = [W]::Capture($vx, $vy, $vw, $vh)
    $bmp.Save($Screenshot, [System.Drawing.Imaging.ImageFormat]::Png); $bmp.Dispose()
    Write-Host "      screenshot: $Screenshot"

    [W]::Keys($VK_CTRL, $VK_ALT, $VK_F); [W]::Keys($VK_CTRL, $VK_ALT, $VK_UP)
    Start-Sleep -Milliseconds 300
    Check 'old hotkeys do nothing' ([W]::IsWindowVisible($ov) -and (Alpha $ov) -eq 153)
    [W]::Focus($winA) | Out-Null

    [W]::PostMessage($ctrl, $WM_TRAY, [IntPtr]::Zero, [IntPtr]$WM_LBUTTONUP) | Out-Null
    Check 'tray left-click toggles off' (WaitFor { -not [W]::IsWindowVisible($ov) })
    Check 'disabled state persisted' (WaitFor { (RegVal Enabled) -eq 0 })
    [W]::PostMessage($ctrl, $WM_TRAY, [IntPtr]::Zero, [IntPtr]$WM_LBUTTONUP) | Out-Null
    [W]::Focus($winA) | Out-Null
    Check 'tray left-click toggles on' (WaitFor { BelowFg $ov })

    function MenuPick([byte[]]$keys) {
        WaitFor { -not [W]::IsWindowVisible([W]::ByClass('#32768')) } | Out-Null
        [W]::Focus($ctrl) | Out-Null
        [W]::PostMessage($ctrl, $WM_TRAY, [IntPtr]::Zero, [IntPtr]$WM_RBUTTONUP) | Out-Null
        if (-not (WaitFor { [W]::IsWindowVisible([W]::ByClass('#32768')) })) { return $false }
        Start-Sleep -Milliseconds 200
        foreach ($k in $keys) { [W]::Keys($k); Start-Sleep -Milliseconds 80 }
        return $true
    }
    # Dim level submenu: second item, Right opens it on 10%, each Down adds 10%.
    function PickDim([int]$pct) { MenuPick (@($VK_DOWN, $VK_DOWN, $VK_RIGHT) + @($VK_DOWN) * (($pct - 10) / 10) + @($VK_RET)) }
    Check 'tray menu opens' (PickDim 70)
    Check 'menu Dim level 70%' (WaitFor { (Alpha $ov) -eq 178 -and (RegVal Opacity) -eq 70 }) "alpha=$(Alpha $ov)"
    PickDim 10 | Out-Null
    Check 'menu Dim level 10%' (WaitFor { (Alpha $ov) -eq 25 -and (RegVal Opacity) -eq 10 }) "alpha=$(Alpha $ov)"
    PickDim 90 | Out-Null
    Check 'menu Dim level 90%' (WaitFor { (Alpha $ov) -eq 229 -and (RegVal Opacity) -eq 90 }) "alpha=$(Alpha $ov)"
    PickDim 60 | Out-Null
    Check 'menu Dim level back to 60%' (WaitFor { (Alpha $ov) -eq 153 -and (RegVal Opacity) -eq 60 }) "alpha=$(Alpha $ov)"

    MenuPick @($VK_DOWN, $VK_RET) | Out-Null
    Check 'menu Enabled unchecks (overlay hidden)' (WaitFor { -not [W]::IsWindowVisible($ov) })
    MenuPick @($VK_DOWN, $VK_RET) | Out-Null
    [W]::Focus($winA) | Out-Null
    Check 'menu Enabled re-checks' (WaitFor { BelowFg $ov })

    MenuPick @($VK_DOWN, $VK_DOWN, $VK_DOWN, $VK_RET) | Out-Null
    Check 'menu "Start with Windows" adds Run entry' (WaitFor { (Autostart) -eq "`"$((Resolve-Path $Exe).Path)`"" }) "value=$(Autostart)"
    MenuPick @($VK_DOWN, $VK_DOWN, $VK_DOWN, $VK_RET) | Out-Null
    Check 'menu "Start with Windows" removes Run entry' (WaitFor { -not (Autostart) })

    $second = Start-Process $Exe -PassThru
    Check 'second instance refuses to start' (WaitFor { [W]::FindWindow('#32770', 'WinFocus') -ne [IntPtr]::Zero })
    $second | Stop-Process -Force

    MenuPick @($VK_UP, $VK_RET) | Out-Null
    Check 'menu Exit quits the app' (WaitFor { $app.HasExited })
}
finally {
    $helpers | % { if (-not $_.HasExited) { $_ | Stop-Process -Force -ErrorAction SilentlyContinue } }
    Get-Process WinFocus -ErrorAction SilentlyContinue | Stop-Process -Force
    Remove-Item HKCU:\Software\WinFocus -Recurse -ErrorAction SilentlyContinue
    Remove-ItemProperty $runKey -Name WinFocus -ErrorAction SilentlyContinue
}

if ($script:fails) { Write-Host "$script:fails check(s) failed" -ForegroundColor Red; exit 1 }
Write-Host 'All checks passed' -ForegroundColor Green
