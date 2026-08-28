/**
 * Theme Manager - Dark Mode Toggle
 * Handles theme switching and persistence (localStorage + server sync)
 */

const ThemeManager = {
  STORAGE_KEY: 'woh_theme',
  PUBLIC_THEME_KEY: 'woh_public_theme',
  mediaQuery: null,

  init() {
    this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    this.loadTheme();
    this.attachMediaQueryListener();
    if (this.isPublicKioskPage()) {
      this.syncFromPublicSettings();
    } else {
      this.syncFromServer();
    }
  },

  isPublicKioskPage() {
    const path = window.location.pathname || '';
    return (path.startsWith('/attendance/') || path.endsWith('/attendance'))
      && !path.includes('attendance-admin');
  },

  loadTheme() {
    const savedTheme = localStorage.getItem(this.STORAGE_KEY) || 'auto';
    this.applyTheme(savedTheme);
  },

  resolveTheme(theme) {
    if (theme === 'auto') {
      const prefersDark = this.mediaQuery ? this.mediaQuery.matches : false;
      return prefersDark ? 'dark' : 'light';
    }
    return theme;
  },

  applyTheme(theme) {
    const resolved = this.resolveTheme(theme);
    document.documentElement.setAttribute('data-theme', resolved);
    document.documentElement.style.colorScheme = resolved;
  },

  setTheme(theme) {
    localStorage.setItem(this.STORAGE_KEY, theme);
    this.applyTheme(theme);
    this.saveToServer(theme);
  },

  attachMediaQueryListener() {
    if (!this.mediaQuery) return;

    this.mediaQuery.addEventListener('change', () => {
      const savedTheme = localStorage.getItem(this.STORAGE_KEY) || 'auto';
      if (savedTheme === 'auto') {
        this.applyTheme('auto');
      }
    });
  },

  // Fetch theme from server and apply it (overrides localStorage)
  // Only runs on authenticated admin pages where auth.js is loaded
  async syncFromServer() {
    if (!window.Auth || !window.Auth.getToken) return;
    const token = window.Auth.getToken();
    if (!token) return;

    try {
      const res = await fetch('/api/auth/me', {
        headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' }
      });
      if ((res.status === 401 || res.status === 403) && window.Auth && !window.Auth.isLoginPage()) {
        window.Auth.redirectToLogin('UNAUTHORIZED');
        return;
      }
      if (!res.ok) return;
      const data = await res.json();
      const serverTheme = data.user && data.user.theme_preference;
      if (serverTheme && ['light', 'dark', 'auto'].includes(serverTheme)) {
        localStorage.setItem(this.STORAGE_KEY, serverTheme);
        this.applyTheme(serverTheme);
      }
    } catch (e) {
      // Server unreachable — keep local preference
    }
  },

  // Public kiosk pages use the system-wide app_theme (ignores device dark mode when set to light/dark)
  async syncFromPublicSettings() {
    try {
      const res = await fetch('/api/system/settings/public-attendance', { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      const systemTheme = data.theme;
      if (systemTheme && ['light', 'dark', 'auto'].includes(systemTheme)) {
        localStorage.setItem(this.PUBLIC_THEME_KEY, systemTheme);
        this.applyTheme(systemTheme);
      }
    } catch (e) {
      // Server unreachable — keep local preference
    }
  },

  // Save theme preference to server (fire-and-forget)
  // Only runs on authenticated admin pages where auth.js is loaded
  async saveToServer(theme) {
    if (!window.Auth || !window.Auth.getToken) return;
    const token = window.Auth.getToken();
    if (!token) return;

    const headers = window.Auth.getHeaders();

    try {
      await fetch('/api/auth/me/theme', {
        method: 'PATCH',
        headers,
        body: JSON.stringify({ theme })
      });
    } catch (e) {
      // Ignore — preference is still in localStorage
    }

    try {
      await fetch('/api/system/settings/app_theme', {
        method: 'PATCH',
        headers,
        body: JSON.stringify({ value: theme })
      });
    } catch (e) {
      // Ignore — preference is still in localStorage
    }
  }
};

// Auto-initialize
window.ThemeManager = ThemeManager;
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => ThemeManager.init());
} else {
  ThemeManager.init();
}
