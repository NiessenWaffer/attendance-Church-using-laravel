require('./bootstrap');

window.Vue = require('vue');

import Buefy from 'buefy';
import App from './App.vue';
import router from './router';

import AppPage from './components/AppPage.vue';
import AppSummaryGrid from './components/AppSummaryGrid.vue';
import AppStatStrip from './components/AppStatStrip.vue';
import AppFilterBar from './components/AppFilterBar.vue';
import AppTablePanel from './components/AppTablePanel.vue';

Vue.use(Buefy);

Vue.component('AppPage', AppPage);
Vue.component('AppSummaryGrid', AppSummaryGrid);
Vue.component('AppStatStrip', AppStatStrip);
Vue.component('AppFilterBar', AppFilterBar);
Vue.component('AppTablePanel', AppTablePanel);

const app = new Vue({
    router,
    render: h => h(App)
}).$mount('#app');
