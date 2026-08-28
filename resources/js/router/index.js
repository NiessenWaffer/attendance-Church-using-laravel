import Vue from 'vue';
import Router from 'vue-router';
import Dashboard from '../pages/Dashboard.vue';
import Members from '../pages/Members.vue';
import Schedules from '../pages/Schedules.vue';
import Attendance from '../pages/Attendance.vue';
import ScanPageRoute from '../pages/ScanPageRoute.vue';
import History from '../pages/History.vue';
import Report from '../pages/Report.vue';
import AuditLogs from '../pages/AuditLogs.vue';
import Settings from '../pages/Settings.vue';
import Login from '../pages/Login.vue';
import { isLoggedIn } from '../auth';

Vue.use(Router);

const router = new Router({
  mode: 'hash',
  routes: [
    { path: '/login', name: 'login', component: Login },
    { path: '/', name: 'dashboard', component: Dashboard },
    { path: '/members', name: 'members', component: Members },
    { path: '/schedules', name: 'schedules', component: Schedules },
    { path: '/attendance', name: 'attendance', component: Attendance },
    { path: '/scan', name: 'scan', component: ScanPageRoute },
    { path: '/ScanPage', redirect: { name: 'scan' } },
    { path: '/scanpage', redirect: { name: 'scan' } },
    { path: '/history', name: 'history', component: History },
    { path: '/report', name: 'report', component: Report },
    { path: '/audit-logs', name: 'audit-logs', component: AuditLogs },
    { path: '/settings', name: 'settings', component: Settings }
  ]
});

router.beforeEach((to, from, next) => {
  if (to.name === 'scan') {
    return next();
  }

  if (to.name === 'login') {
    if (isLoggedIn()) {
      return next({ name: 'dashboard' });
    }
    return next();
  }

  if (!isLoggedIn()) {
    return next({ name: 'login' });
  }

  next();
});

export default router;
