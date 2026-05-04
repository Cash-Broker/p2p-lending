import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import HomePage from '../views/HomePage.vue'
import AppLayout from '../layouts/AppLayout.vue'

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomePage,
  },

  // Auth pages (no layout)
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/auth/LoginPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('../views/auth/RegisterPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('../views/auth/ForgotPasswordPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/reset-password/:token',
    name: 'reset-password',
    component: () => import('../views/auth/ResetPasswordPage.vue'),
    meta: { guest: true },
  },
  {
    path: '/verify-email',
    name: 'verify-email',
    component: () => import('../views/auth/EmailVerificationPage.vue'),
  },

  // Legal pages — public, no auth required
  {
    path: '/legal/terms',
    name: 'legal-terms',
    component: () => import('../views/legal/TermsPage.vue'),
  },
  {
    path: '/legal/privacy',
    name: 'legal-privacy',
    component: () => import('../views/legal/PrivacyPage.vue'),
  },
  {
    path: '/legal/cookies',
    name: 'legal-cookies',
    component: () => import('../views/legal/CookiesPage.vue'),
  },
  {
    path: '/legal/risk',
    name: 'legal-risk',
    component: () => import('../views/legal/RiskPage.vue'),
  },

  // App pages (with sidebar layout)
  {
    path: '/',
    component: AppLayout,
    meta: { auth: true },
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('../views/DashboardPage.vue'),
      },
      {
        path: 'deposit',
        name: 'deposit',
        component: () => import('../views/DepositPage.vue'),
      },
      {
        path: 'withdraw',
        name: 'withdraw',
        component: () => import('../views/WithdrawalPage.vue'),
      },
      {
        path: 'invest',
        name: 'invest',
        component: () => import('../views/MarketplacePage.vue'),
      },
      {
        path: 'invest/:id',
        name: 'invest-detail',
        component: () => import('../views/InvestmentDetailPage.vue'),
      },
      {
        path: 'portfolio',
        name: 'portfolio',
        component: () => import('../views/PortfolioPage.vue'),
      },
      {
        path: 'transactions',
        name: 'transactions',
        component: () => import('../views/TransactionsPage.vue'),
      },
      {
        path: 'profile',
        name: 'profile',
        component: () => import('../views/ProfilePage.vue'),
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

let userFetched = false

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Fetch user once per page load, not on every navigation
  if (!userFetched) {
    userFetched = true
    await auth.fetchUser()
  }

  // Admin users should use /admin panel, not the investor frontend
  if (auth.user?.role === 'admin' && to.meta.auth) {
    window.location.href = '/admin'
    return false
  }

  if (to.meta.auth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // Guest routes: redirect investors to dashboard, admins to admin panel
  if (to.meta.guest && auth.isAuthenticated) {
    if (auth.user?.role === 'admin') {
      window.location.href = '/admin'
      return false
    }
    return { name: 'dashboard' }
  }
})

export default router
