const TOKEN_KEY = 'cas_token';
const AUTH_KEY = 'cas_auth';
const USER_KEY = 'cas_user';

export function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function isLoggedIn() {
  return !!getToken();
}

export function login(user, token) {
  localStorage.setItem(TOKEN_KEY, token || '');
  localStorage.setItem(AUTH_KEY, '1');
  if (user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }
}

export function logout() {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(AUTH_KEY);
  localStorage.removeItem(USER_KEY);
}
