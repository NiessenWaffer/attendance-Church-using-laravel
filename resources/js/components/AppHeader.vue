<template>
  <header class="header">
    <div class="brand">
      <button class="menu-toggle" type="button" aria-label="Toggle menu" @click="$emit('toggle-menu')">
        <b-icon icon="menu" size="is-small"></b-icon>
      </button>
      <div class="brand-icon">
        <img src="/images/WOHLOGO.png" alt="WOH Logo" class="brand-logo" />
      </div>
      <div class="brand-text">
        <span class="system-title">Church Attendance</span>
        <span class="system-tag">Management System</span>
      </div>
    </div>

    <div class="header-actions">
      <div class="user-badge">
        <div class="avatar-circle">A</div>
        <div class="user-info">
          <span class="user-name">Administrator</span>
          <span class="user-role">System Admin</span>
        </div>
      </div>
      <button class="logout-btn" @click="$emit('logout')">
        <b-icon icon="logout-variant" size="is-small"></b-icon>
        <span>Logout</span>
      </button>
    </div>
  </header>
</template>

<script>
export default { name: 'Header' };
</script>

<style scoped>
.header {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 56px;
  z-index: 1000;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #0f172a;
  color: #ffffff;
  padding: 0 20px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  border-bottom: 1px solid #1e293b;
  box-sizing: border-box;
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
}

.menu-toggle {
  display: none;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: 0;
  color: #cbd5e1;
  padding: 4px 6px;
  cursor: pointer;
}

.menu-toggle:hover {
  color: #ffffff;
}

.brand-icon {
  width: 32px;
  height: 32px;
  background: #1e293b;
  border: 1px solid #334155;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.brand-logo {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.brand-text {
  display: flex;
  flex-direction: column;
}

.system-title {
  font-size: 14px;
  font-weight: 700;
  color: #f8fafc;
  letter-spacing: 0.02em;
  line-height: 1.2;
}

.system-tag {
  font-size: 10px;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 16px;
}

.user-badge {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-right: 12px;
  border-right: 1px solid #1e293b;
}

.avatar-circle {
  width: 28px;
  height: 28px;
  background: #334155;
  color: #f8fafc;
  border-radius: 50%;
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.user-info {
  display: flex;
  flex-direction: column;
}

.user-name {
  font-size: 12px;
  font-weight: 600;
  color: #e2e8f0;
  line-height: 1.2;
}

.user-role {
  font-size: 10px;
  color: #94a3b8;
}

.logout-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: transparent;
  border: 1px solid #334155;
  color: #cbd5e1;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  border-radius: 4px;
  transition: all 0.15s ease;
}

.logout-btn:hover {
  background: #1e293b;
  color: #ffffff;
  border-color: #475569;
}

@media (max-width: 768px) {
  .menu-toggle {
    display: inline-flex;
  }

  .header {
    height: 48px;
    padding: 0 12px;
  }

  .brand {
    gap: 8px;
  }

  .system-tag,
  .user-info,
  .logout-btn span {
    display: none;
  }

  .logout-btn {
    padding: 5px 9px;
  }

  .user-badge {
    padding-right: 8px;
  }

  .header-actions {
    gap: 8px;
  }
}

@media (max-width: 400px) {
  .header {
    padding: 0 8px;
  }

  .brand-icon {
    width: 28px;
    height: 28px;
  }

  .system-title {
    max-width: 142px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
  }

  .user-badge {
    display: none;
  }
}
</style>
