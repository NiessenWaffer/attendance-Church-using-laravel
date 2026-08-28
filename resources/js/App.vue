<template>
  <div id="app">
    <template v-if="isAuthPage">
      <router-view />
    </template>

    <template v-else>
      <Header @logout="confirmLogout" @toggle-menu="sidebarOpen = !sidebarOpen" />
      <div class="app-body">
        <SideBar :open="sidebarOpen" @close="sidebarOpen = false" />
        <main class="app-content">
          <router-view />
        </main>
      </div>
    </template>
  </div>
</template>

<script>
import Header from './components/AppHeader.vue';
import SideBar from './components/AppSidebar.vue';
import { logout as clearAuth } from './auth';
import { api, errorMessage } from './services/api';

export default {
  name: 'App',
  components: { Header, SideBar },
  data() {
    return {
      sidebarOpen: false,
    };
  },
  computed: {
    isAuthPage() {
      return this.$route.name === 'login';
    }
  },
  watch: {
    '$route'() {
      this.sidebarOpen = false;
    }
  },
  methods: {
    confirmLogout() {
      this.$buefy.dialog.confirm({
        title: 'Log Out',
        message: 'Are you sure you want to log out?',
        confirmText: 'Log Out',
        cancelText: 'Cancel',
        type: 'is-dark',
        onConfirm: () => this.performLogout(),
      });
    },

    async performLogout() {
      let logoutError = null;
      try {
        await api.post('/api/logout');
      } catch (e) {
        logoutError = errorMessage(e, 'The server could not complete logout.');
      } finally {
        clearAuth();
        if (this.$route.name !== 'login') {
          await this.$router.push({ name: 'login' });
        }
      }
      if (logoutError) {
        this.$buefy.toast.open({
          message: `${logoutError} Local session data was cleared.`,
          type: 'is-warning',
        });
      }
    }
  }
};
</script>

<style>
html, body {
  margin: 0;
  padding: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

*,
*::before,
*::after {
  box-sizing: border-box;
}

img,
svg,
video,
canvas {
  max-width: 100%;
}

#app {
  --sidebar-w: 250px;
  height: 100vh;
  height: 100dvh;
  display: flex;
  flex-direction: column;
  font-family: "Segoe UI", Arial, sans-serif;
  overflow: hidden;
}

.app-body {
  display: flex;
  flex: 1;
  margin-top: 56px;
  height: calc(100vh - 56px);
  height: calc(100dvh - 56px);
  overflow: hidden;
}

.app-content {
  flex: 1;
  margin-left: var(--sidebar-w, 250px);
  padding: 0;
  background: #f7f7f7;
  height: calc(100vh - 56px);
  height: calc(100dvh - 56px);
  box-sizing: border-box;
  overflow: hidden;
}

@media (max-width: 1440px) {
  #app { --sidebar-w: 220px; }
}

@media (max-width: 1180px) {
  #app { --sidebar-w: 60px; }
}

@media (max-width: 768px) {
  #app { --sidebar-w: 0px; }

  .app-body {
    margin-top: 48px;
    height: calc(100vh - 48px);
    height: calc(100dvh - 48px);
  }

  .app-content {
    min-width: 0;
    height: calc(100vh - 48px);
    height: calc(100dvh - 48px);
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior-y: contain;
    -webkit-overflow-scrolling: touch;
  }
}
</style>
