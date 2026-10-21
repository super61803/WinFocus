# WinFocus

A lightweight focus utility for Windows that dims everything on screen except the active window. Built with Rust and the Win32 API.

## Download

[WinFocus.exe](https://github.com/super61803/Cxspsa-BE/raw/main/dist/WinFocus.exe)

## Prerequisites

- Windows 10 or 11
- [Rust](https://rustup.rs/) (stable, MSVC toolchain)
- Visual Studio Build Tools with the "Desktop development with C++" workload

## Local development

```powershell
# Run a debug build
cargo run

# Build an optimized release binary (target\release\WinFocus.exe)
cargo build --release
```

The app has no main window; it runs from the system tray. Only one instance can run at a time, so exit the tray app before starting a new build.

## Testing

End-to-end test against the release build:

```powershell
cargo build --release
powershell -ExecutionPolicy Bypass -File scripts\e2e.ps1
```
