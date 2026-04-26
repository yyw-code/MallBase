const ENV = {
  development: {
    baseUrl: 'http://localhost:8080'
  },
  production: {
    baseUrl: ''
  }
}

const env = process.env.NODE_ENV || 'development'

export default {
  ...ENV[env] || ENV.development,
  apiPrefix: '/client/api',
  version: '1.0.0'
}
