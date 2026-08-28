<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-brand">
        <h1>{{ title }}</h1>
        <p>{{ subtitle }}</p>
      </div>

      <form @submit.prevent="login">
        <div v-if="error" class="login-error">
          {{ error }}
        </div>

        <b-field
          v-for="field in fields"
          :key="field.key"
          :label="field.label"
          label-position="on-border"
        >
          <b-input
            v-model="form[field.key]"
            :type="field.type"
            :placeholder="field.placeholder"
            :icon="field.icon"
            :password-reveal="field.passwordReveal"
            @input="error = ''"
          />
        </b-field>

        <label class="login-remember">
          <input type="checkbox" v-model="remember" />
          <span>{{ rememberLabel }}</span>
        </label>

        <button class="login-button" type="submit" :disabled="loading">
          <span v-if="loading" class="login-spinner"></span>
          <span>{{ loading ? loadingLabel : submitLabel }}</span>
        </button>
      </form>

      <p class="login-footer">{{ footer }}</p>
    </div>
  </div>
</template>

<script>
import { login as setAuth } from '../auth';

export default {
  name: 'LoginPage',

  props: {
    title: {
      type: String,
      default: 'Sign in',
    },
    subtitle: {
      type: String,
      default: 'Enter your account credentials to continue.',
    },
    submitLabel: {
      type: String,
      default: 'Sign in',
    },
    loadingLabel: {
      type: String,
      default: 'Signing in...',
    },
    rememberLabel: {
      type: String,
      default: 'Remember me',
    },
    footer: {
      type: String,
      default: 'Restricted access. Only authorized WOH Caloocan admins may sign in.',
    },
  },

  data() {
    const fields = [
      {
        key: 'login',
        label: 'Username or Email',
        type: 'text',
        placeholder: 'Enter your username or email',
        icon: 'account',
      },
      {
        key: 'password',
        label: 'Password',
        type: 'password',
        placeholder: 'Enter your password',
        icon: 'lock',
        passwordReveal: true,
      },
    ];

    return {
      fields,
      form: {
        login: '',
        password: '',
      },
      remember: false,
      loading: false,
      error: '',
    };
  },

  methods: {
    async login() {
      this.error = '';

      const missing = this.fields.filter((field) => !this.form[field.key]);
      if (missing.length) {
        this.error = 'All fields are required.';
        return;
      }

      this.loading = true;

      try {
        const response = await axios.post('/api/login', this.form);
        setAuth(response.data.data.user, response.data.data.token);
        this.$router.push('/');
      } catch (err) {
        this.error =
          err.response && err.response.data && err.response.data.message
            ? err.response.data.message
            : 'Unable to sign in. Please try again.';
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>
