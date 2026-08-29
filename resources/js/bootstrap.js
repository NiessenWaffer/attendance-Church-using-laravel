window._ = require('lodash');

import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const baseUrl = document.querySelector('meta[name="base-url"]').getAttribute('content');
window.axios.defaults.baseURL = baseUrl.replace(/\/$/, '');
