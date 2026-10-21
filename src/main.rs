//! WinFocus: dims everything on screen except the active window.

#![windows_subsystem = "windows"]

use std::ffi::c_void;
use std::mem::{size_of, zeroed};
use std::ptr::{null, null_mut};
use std::sync::atomic::{AtomicBool, AtomicPtr, AtomicU32, Ordering::Relaxed};

use windows_sys::Win32::Foundation::*;
use windows_sys::Win32::Graphics::Gdi::*;
use windows_sys::Win32::System::LibraryLoader::{GetModuleFileNameW, GetModuleHandleW};
use windows_sys::Win32::System::Registry::*;
use windows_sys::Win32::System::Threading::{
    CreateMutexW, GetCurrentProcess, SetPriorityClass, ABOVE_NORMAL_PRIORITY_CLASS,
};
use windows_sys::Win32::UI::Accessibility::{SetWinEventHook, HWINEVENTHOOK};
use windows_sys::Win32::UI::HiDpi::{
    SetProcessDpiAwarenessContext, DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2,
};
use windows_sys::Win32::UI::Shell::*;
use windows_sys::Win32::UI::WindowsAndMessaging::*;

const WM_TRAY: u32 = WM_APP + 1;
// Short burst of re-checks after a focus change, to catch the shell raising the
// window again once activation finishes. No timer runs while idle.
const TIMER_BURST: usize = 1;
const BURST_MS: u32 = 10;
const BURST_TICKS: u32 = 30;

const ID_TOGGLE: usize = 100;
const ID_AUTOSTART: usize = 101;
const ID_EXIT: usize = 102;
const ID_OPACITY: usize = 200; // + percent

const OPACITY_MIN: u32 = 10;
const OPACITY_MAX: u32 = 90;
const OPACITY_STEP: u32 = 10;

const APP_NAME: &str = "WinFocus";
const REG_KEY: &str = r"Software\WinFocus";
const RUN_KEY: &str = r"Software\Microsoft\Windows\CurrentVersion\Run";

static OVERLAY: AtomicPtr<c_void> = AtomicPtr::new(null_mut());
static CTRL: AtomicPtr<c_void> = AtomicPtr::new(null_mut());
static ICON_ON: AtomicPtr<c_void> = AtomicPtr::new(null_mut());
static ICON_OFF: AtomicPtr<c_void> = AtomicPtr::new(null_mut());
static ENABLED: AtomicBool = AtomicBool::new(true);
static OPACITY: AtomicU32 = AtomicU32::new(60);
static TASKBAR_CREATED: AtomicU32 = AtomicU32::new(0);
static BURST_LEFT: AtomicU32 = AtomicU32::new(0);

fn wide(s: &str) -> Vec<u16> {
    s.encode_utf16().chain(Some(0)).collect()
}

fn main() {
    unsafe { run() }
}

unsafe fn run() {
    SetProcessDpiAwarenessContext(DPI_AWARENESS_CONTEXT_PER_MONITOR_AWARE_V2);
    SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS);

    let mutex_name = wide("Local\\WinFocus.SingleInstance");
    let _mutex = CreateMutexW(null(), 0, mutex_name.as_ptr());
    if GetLastError() == ERROR_ALREADY_EXISTS {
        MessageBoxW(
            null_mut(),
            wide("WinFocus is already running. Look for it in the system tray.").as_ptr(),
            wide(APP_NAME).as_ptr(),
            MB_ICONINFORMATION,
        );
        return;
    }

    load_settings();

    let hinst = GetModuleHandleW(null());
    let overlay_class = wide("WinFocusOverlay");
    let ctrl_class = wide("WinFocusController");

    let mut wc: WNDCLASSEXW = zeroed();
    wc.cbSize = size_of::<WNDCLASSEXW>() as u32;
    wc.hInstance = hinst;
    wc.hCursor = LoadCursorW(null_mut(), IDC_ARROW);

    wc.lpfnWndProc = Some(overlay_proc);
    wc.hbrBackground = GetStockObject(BLACK_BRUSH) as HBRUSH;
    wc.lpszClassName = overlay_class.as_ptr();
    RegisterClassExW(&wc);

    wc.lpfnWndProc = Some(ctrl_proc);
    wc.hbrBackground = null_mut();
    wc.lpszClassName = ctrl_class.as_ptr();
    RegisterClassExW(&wc);

    let (x, y, w, h) = virtual_screen();
    let overlay = CreateWindowExW(
        WS_EX_LAYERED | WS_EX_TRANSPARENT | WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE,
        overlay_class.as_ptr(),
        wide("WinFocus Overlay").as_ptr(),
        WS_POPUP,
        x,
        y,
        w,
        h,
        null_mut(),
        null_mut(),
        hinst,
        null(),
    );
    OVERLAY.store(overlay, Relaxed);

    let ctrl = CreateWindowExW(
        WS_EX_TOOLWINDOW,
        ctrl_class.as_ptr(),
        wide(APP_NAME).as_ptr(),
        WS_POPUP,
        0,
        0,
        0,
        0,
        null_mut(),
        null_mut(),
        hinst,
        null(),
    );
    CTRL.store(ctrl, Relaxed);

    ICON_ON.store(make_icon(true), Relaxed);
    ICON_OFF.store(make_icon(false), Relaxed);
    TASKBAR_CREATED.store(
        RegisterWindowMessageW(wide("TaskbarCreated").as_ptr()),
        Relaxed,
    );
    tray(NIM_ADD);

    let flags = WINEVENT_OUTOFCONTEXT | WINEVENT_SKIPOWNPROCESS;
    SetWinEventHook(EVENT_SYSTEM_FOREGROUND, EVENT_SYSTEM_FOREGROUND, null_mut(), Some(win_event), 0, 0, flags);
    SetWinEventHook(EVENT_SYSTEM_MINIMIZESTART, EVENT_SYSTEM_MINIMIZEEND, null_mut(), Some(win_event), 0, 0, flags);
    SetWinEventHook(EVENT_OBJECT_SHOW, EVENT_OBJECT_HIDE, null_mut(), Some(win_event), 0, 0, flags);

    apply_opacity();
    update();

    let mut msg: MSG = zeroed();
    while GetMessageW(&mut msg, null_mut(), 0, 0) > 0 {
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }
}

// ---------------------------------------------------------------------------
// Overlay placement
// ---------------------------------------------------------------------------

fn virtual_screen() -> (i32, i32, i32, i32) {
    unsafe {
        (
            GetSystemMetrics(SM_XVIRTUALSCREEN),
            GetSystemMetrics(SM_YVIRTUALSCREEN),
            GetSystemMetrics(SM_CXVIRTUALSCREEN),
            GetSystemMetrics(SM_CYVIRTUALSCREEN),
        )
    }
}

/// Desktop windows: nothing to highlight, so the overlay is hidden.
const DESKTOP_CLASSES: &[&str] = &["Progman", "WorkerW"];

/// Transient shell UI (taskbar, Start, Alt-Tab, menus): the overlay is left as is.
const SHELL_CLASSES: &[&str] = &[
    "Shell_TrayWnd",
    "Shell_SecondaryTrayWnd",
    "NotifyIconOverflowWindow",
    "TopLevelWindowForOverflowXamlIsland",
    "Windows.UI.Core.CoreWindow",
    "XamlExplorerHostIslandWindow",
    "MultitaskingViewFrame",
    "ForegroundStaging",
    "TaskListThumbnailWnd",
    "#32768",
];

unsafe fn class_in(hwnd: HWND, list: &[&str]) -> bool {
    let mut buf = [0u16; 64];
    let n = GetClassNameW(hwnd, buf.as_mut_ptr(), buf.len() as i32).max(0) as usize;
    let name = &buf[..n];
    list.iter().any(|c| c.encode_utf16().eq(name.iter().copied()))
}

/// Puts the overlay directly beneath the foreground window in Z-order so that
/// only the foreground window (and anything above it, like the taskbar) stays bright.
unsafe fn update() {
    let overlay = OVERLAY.load(Relaxed);
    if overlay.is_null() {
        return;
    }
    let hide = || {
        if IsWindowVisible(overlay) != 0 {
            ShowWindow(overlay, SW_HIDE);
        }
    };
    if !ENABLED.load(Relaxed) {
        hide();
        return;
    }

    let fg = GetForegroundWindow();
    if fg.is_null() || fg == overlay || fg == CTRL.load(Relaxed) {
        return;
    }
    if class_in(fg, DESKTOP_CLASSES) || IsIconic(fg) != 0 {
        return hide();
    }
    // Not shown yet (e.g. a window still opening): wait for its EVENT_OBJECT_SHOW.
    if IsWindowVisible(fg) == 0 || class_in(fg, SHELL_CLASSES) {
        return;
    }

    let (x, y, w, h) = virtual_screen();
    let mut r: RECT = zeroed();
    GetWindowRect(overlay, &mut r);
    let in_place = IsWindowVisible(overlay) != 0
        && next_visible(fg) == overlay
        && r.left == x
        && r.top == y
        && r.right - r.left == w
        && r.bottom - r.top == h;
    if !in_place {
        SetWindowPos(
            overlay,
            fg,
            x,
            y,
            w,
            h,
            SWP_NOACTIVATE | SWP_NOOWNERZORDER | SWP_NOSENDCHANGING | SWP_SHOWWINDOW,
        );
    }
}

unsafe fn next_visible(hwnd: HWND) -> HWND {
    let mut h = GetWindow(hwnd, GW_HWNDNEXT);
    while !h.is_null() && IsWindowVisible(h) == 0 && h != OVERLAY.load(Relaxed) {
        h = GetWindow(h, GW_HWNDNEXT);
    }
    h
}

unsafe fn apply_opacity() {
    let alpha = (OPACITY.load(Relaxed) * 255 / 100) as u8;
    SetLayeredWindowAttributes(OVERLAY.load(Relaxed), 0, alpha, LWA_ALPHA);
}

unsafe fn set_enabled(on: bool) {
    ENABLED.store(on, Relaxed);
    save_settings();
    tray(NIM_MODIFY);
    update();
}

unsafe fn set_opacity(percent: u32) {
    OPACITY.store(percent.clamp(OPACITY_MIN, OPACITY_MAX), Relaxed);
    apply_opacity();
    save_settings();
    tray(NIM_MODIFY);
}

// ---------------------------------------------------------------------------
// Window procedures and event hooks
// ---------------------------------------------------------------------------

unsafe extern "system" fn win_event(
    _hook: HWINEVENTHOOK,
    event: u32,
    hwnd: HWND,
    id_object: i32,
    id_child: i32,
    _thread: u32,
    _time: u32,
) {
    match event {
        EVENT_SYSTEM_FOREGROUND => {
            update();
            BURST_LEFT.store(BURST_TICKS, Relaxed);
            SetTimer(CTRL.load(Relaxed), TIMER_BURST, BURST_MS, None);
        }
        // Show/hide fire for every control on the system; only the foreground
        // top-level window matters.
        EVENT_OBJECT_SHOW | EVENT_OBJECT_HIDE => {
            if id_object == OBJID_WINDOW
                && id_child == CHILDID_SELF as i32
                && hwnd == GetForegroundWindow()
            {
                update();
            }
        }
        _ => update(),
    }
}

unsafe extern "system" fn overlay_proc(hwnd: HWND, msg: u32, wp: WPARAM, lp: LPARAM) -> LRESULT {
    match msg {
        WM_NCHITTEST => HTTRANSPARENT as LRESULT,
        WM_MOUSEACTIVATE => MA_NOACTIVATE as LRESULT,
        _ => DefWindowProcW(hwnd, msg, wp, lp),
    }
}

unsafe extern "system" fn ctrl_proc(hwnd: HWND, msg: u32, wp: WPARAM, lp: LPARAM) -> LRESULT {
    match msg {
        WM_TRAY => {
            match (lp as u32) & 0xFFFF {
                WM_LBUTTONUP => set_enabled(!ENABLED.load(Relaxed)),
                WM_RBUTTONUP | WM_CONTEXTMENU => show_menu(hwnd),
                _ => {}
            }
            0
        }
        WM_TIMER => {
            update();
            if BURST_LEFT.fetch_sub(1, Relaxed) <= 1 {
                KillTimer(hwnd, wp);
            }
            0
        }
        WM_DISPLAYCHANGE => {
            update();
            0
        }
        WM_DESTROY => {
            tray(NIM_DELETE);
            PostQuitMessage(0);
            0
        }
        _ if msg == TASKBAR_CREATED.load(Relaxed) && msg != 0 => {
            tray(NIM_ADD);
            0
        }
        _ => DefWindowProcW(hwnd, msg, wp, lp),
    }
}

// ---------------------------------------------------------------------------
// Tray icon and menu
// ---------------------------------------------------------------------------

unsafe fn tray(action: NOTIFY_ICON_MESSAGE) {
    let mut nid: NOTIFYICONDATAW = zeroed();
    nid.cbSize = size_of::<NOTIFYICONDATAW>() as u32;
    nid.hWnd = CTRL.load(Relaxed);
    nid.uID = 1;
    nid.uFlags = NIF_ICON | NIF_MESSAGE | NIF_TIP;
    nid.uCallbackMessage = WM_TRAY;
    let on = ENABLED.load(Relaxed);
    nid.hIcon = if on { ICON_ON.load(Relaxed) } else { ICON_OFF.load(Relaxed) };
    let tip = format!(
        "WinFocus: {} (dim {}%)",
        if on { "On" } else { "Off" },
        OPACITY.load(Relaxed)
    );
    for (dst, src) in nid.szTip.iter_mut().zip(tip.encode_utf16().take(127)) {
        *dst = src;
    }
    Shell_NotifyIconW(action, &nid);
}

unsafe fn show_menu(hwnd: HWND) {
    let check = |b: bool| if b { MF_CHECKED } else { MF_UNCHECKED };
    let menu = CreatePopupMenu();
    let dim = CreatePopupMenu();
    let op = OPACITY.load(Relaxed);
    for p in (OPACITY_MIN..=OPACITY_MAX).step_by(OPACITY_STEP as usize) {
        AppendMenuW(dim, MF_STRING | check(p == op), ID_OPACITY + p as usize, wide(&format!("{p}%")).as_ptr());
    }
    AppendMenuW(menu, MF_STRING | check(ENABLED.load(Relaxed)), ID_TOGGLE, wide("Enabled").as_ptr());
    AppendMenuW(menu, MF_POPUP, dim as usize, wide("Dim level").as_ptr());
    AppendMenuW(menu, MF_STRING | check(autostart_enabled()), ID_AUTOSTART, wide("Start with Windows").as_ptr());
    AppendMenuW(menu, MF_SEPARATOR, 0, null());
    AppendMenuW(menu, MF_STRING, ID_EXIT, wide("Exit").as_ptr());

    let mut pt: POINT = zeroed();
    GetCursorPos(&mut pt);
    SetForegroundWindow(hwnd);
    let cmd = TrackPopupMenu(
        menu,
        TPM_RETURNCMD | TPM_RIGHTBUTTON | TPM_NONOTIFY,
        pt.x,
        pt.y,
        0,
        hwnd,
        null(),
    ) as usize;
    PostMessageW(hwnd, WM_NULL, 0, 0);
    DestroyMenu(menu);

    match cmd {
        ID_TOGGLE => set_enabled(!ENABLED.load(Relaxed)),
        ID_AUTOSTART => set_autostart(!autostart_enabled()),
        ID_EXIT => {
            DestroyWindow(hwnd);
        }
        c if c > ID_OPACITY => set_opacity((c - ID_OPACITY) as u32),
        _ => {}
    }
}

/// Draws a 32x32 "spotlight" icon: dark disc with a light rim and a coloured centre.
unsafe fn make_icon(on: bool) -> HICON {
    const S: i32 = 32;
    let mut bmi: BITMAPINFO = zeroed();
    bmi.bmiHeader.biSize = size_of::<BITMAPINFOHEADER>() as u32;
    bmi.bmiHeader.biWidth = S;
    bmi.bmiHeader.biHeight = -S;
    bmi.bmiHeader.biPlanes = 1;
    bmi.bmiHeader.biBitCount = 32;
    bmi.bmiHeader.biCompression = BI_RGB;

    let mut bits: *mut c_void = null_mut();
    let hdc = GetDC(null_mut());
    let color = CreateDIBSection(hdc, &bmi, DIB_RGB_COLORS, &mut bits, null_mut(), 0);
    ReleaseDC(null_mut(), hdc);
    let px = std::slice::from_raw_parts_mut(bits as *mut u32, (S * S) as usize);

    let center: u32 = if on { 0xFFC83D } else { 0x8A8A8A };
    for y in 0..S {
        for x in 0..S {
            let dx = x as f32 + 0.5 - S as f32 / 2.0;
            let dy = y as f32 + 0.5 - S as f32 / 2.0;
            let d = (dx * dx + dy * dy).sqrt();
            let rgb = if d < 7.5 {
                center
            } else if d < 13.0 {
                0x2A2A2A
            } else {
                0xF0F0F0
            };
            let alpha = ((15.5 - d).clamp(0.0, 1.0) * 255.0) as u32;
            px[(y * S + x) as usize] = (alpha << 24) | rgb;
        }
    }

    let mask_bits = [0u8; (S * S / 8) as usize];
    let mask = CreateBitmap(S, S, 1, 1, mask_bits.as_ptr() as *const c_void);
    let info = ICONINFO {
        fIcon: 1,
        xHotspot: 0,
        yHotspot: 0,
        hbmMask: mask,
        hbmColor: color,
    };
    let icon = CreateIconIndirect(&info);
    DeleteObject(color);
    DeleteObject(mask);
    icon
}

// ---------------------------------------------------------------------------
// Settings (HKCU registry)
// ---------------------------------------------------------------------------

unsafe fn reg_get_dword(name: &str) -> Option<u32> {
    let mut value = 0u32;
    let mut size = size_of::<u32>() as u32;
    let r = RegGetValueW(
        HKEY_CURRENT_USER,
        wide(REG_KEY).as_ptr(),
        wide(name).as_ptr(),
        RRF_RT_REG_DWORD,
        null_mut(),
        &mut value as *mut u32 as *mut c_void,
        &mut size,
    );
    (r == ERROR_SUCCESS).then_some(value)
}

unsafe fn reg_set(subkey: &str, name: &str, kind: REG_VALUE_TYPE, data: &[u8]) {
    let mut key: HKEY = null_mut();
    let r = RegCreateKeyExW(
        HKEY_CURRENT_USER,
        wide(subkey).as_ptr(),
        0,
        null(),
        REG_OPTION_NON_VOLATILE,
        KEY_SET_VALUE,
        null(),
        &mut key,
        null_mut(),
    );
    if r == ERROR_SUCCESS {
        RegSetValueExW(key, wide(name).as_ptr(), 0, kind, data.as_ptr(), data.len() as u32);
        RegCloseKey(key);
    }
}

unsafe fn load_settings() {
    if let Some(v) = reg_get_dword("Enabled") {
        ENABLED.store(v != 0, Relaxed);
    }
    if let Some(v) = reg_get_dword("Opacity") {
        OPACITY.store(v.clamp(OPACITY_MIN, OPACITY_MAX), Relaxed);
    }
}

unsafe fn save_settings() {
    reg_set(REG_KEY, "Enabled", REG_DWORD, &(ENABLED.load(Relaxed) as u32).to_le_bytes());
    reg_set(REG_KEY, "Opacity", REG_DWORD, &OPACITY.load(Relaxed).to_le_bytes());
}

unsafe fn autostart_enabled() -> bool {
    RegGetValueW(
        HKEY_CURRENT_USER,
        wide(RUN_KEY).as_ptr(),
        wide(APP_NAME).as_ptr(),
        RRF_RT_REG_SZ,
        null_mut(),
        null_mut(),
        null_mut(),
    ) == ERROR_SUCCESS
}

unsafe fn set_autostart(on: bool) {
    if on {
        let mut buf = [0u16; 1024];
        let n = GetModuleFileNameW(null_mut(), buf.as_mut_ptr(), buf.len() as u32) as usize;
        let cmd = format!("\"{}\"", String::from_utf16_lossy(&buf[..n]));
        let data: Vec<u8> = wide(&cmd).iter().flat_map(|c| c.to_le_bytes()).collect();
        reg_set(RUN_KEY, APP_NAME, REG_SZ, &data);
    } else {
        RegDeleteKeyValueW(HKEY_CURRENT_USER, wide(RUN_KEY).as_ptr(), wide(APP_NAME).as_ptr());
    }
}
