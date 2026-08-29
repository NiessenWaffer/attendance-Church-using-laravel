import axios from 'axios';

axios.defaults.timeout = 15000;
axios.defaults.headers.common.Accept = 'application/json';

axios.interceptors.request.use((config) => {
  const token = localStorage.getItem('cas_token');
  const headers = config && config.headers ? { ...config.headers } : {};
  if (token) headers.Authorization = `Bearer ${token}`;

  return {
    ...config,
    headers,
  };
});

axios.interceptors.response.use(
  (response) => {
    const url = response.config && response.config.url ? response.config.url : '';
    const contentType = String(response.headers && response.headers['content-type'] || '').toLowerCase();
    const isApiRequest = /(^|\/)api(?:\/|$)/.test(url);
    const isHtml = contentType.includes('text/html')
      || (typeof response.data === 'string' && /^\s*<!doctype html/i.test(response.data));

    if (isApiRequest && isHtml) {
      const error = new Error('The API returned an HTML page instead of the expected response.');
      error.response = response;
      return Promise.reject(error);
    }

    return response;
  },
  (error) => {
    if (error.response && error.response.status === 401) {
      const hash = (window.location.hash || '').replace('#', '');
      if (hash !== '/login') {
        localStorage.removeItem('cas_token');
        localStorage.removeItem('cas_auth');
        localStorage.removeItem('cas_user');
        window.location.hash = '#/login';
      }
    }
    return Promise.reject(error);
  }
);

async function request(method, url, { params = {}, data = null } = {}) {
  const response = await axios({ method, url, params, data });
  return response.data;
}

export const api = {
  get: (url, params) => request('get', url, { params }),
  post: (url, data) => request('post', url, { data }),
  put: (url, data) => request('put', url, { data }),
  patch: (url, data) => request('patch', url, { data }),
  delete: (url) => request('delete', url),
};

export async function downloadFile(url, filename, params = {}) {
  const response = await axios({ method: 'get', url, params, responseType: 'blob' });
  const blobUrl = window.URL.createObjectURL(new Blob([response.data]));
  const link = document.createElement('a');
  link.href = blobUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(blobUrl);
}

export function errorMessage(error, fallback = 'Something went wrong.') {
  return error.response && error.response.data && error.response.data.message
    ? error.response.data.message
    : fallback;
}
