import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { loansPageRedirect } from '../utils/publicGate'
import HomePage from '../views/HomePage.vue'
import AppLayout from '../layouts/AppLayout.vue'

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomePage,
  },

  // Public landing subpages behind the «Кредити» / «Оригинатори» nav items
  // (Reni 2026-08-20). Deliberately NOT meta.auth: a guest must reach them
  // and be told to register, not be bounced to /login. Neither page calls a
  // data endpoint — /loans redirects an approved investor to their own
  // positions instead of re-rendering money on a public page.
  {
    path: '/loans',
    name: 'public-loans',
    component: () => import('../views/LoansPage.vue'),
    // Runs after the global beforeEach, so the user is already fetched.
    beforeEnter: () => {
      const target = loansPageRedirect(useAuthStore().user)

      return target ? { path: target } : true
    },
  },
  {
    path: '/originators',
    name: 'public-originators',
    component: () => import('../views/OriginatorsPage.vue'),
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
        // Private-loan share link: resolves the token (grants access) then
        // redirects to the loan. Auth is handled by the parent meta.auth +
        // the login redirect-back guard.
        path: 'invest/shared/:token',
        name: 'invest-shared',
        component: () => import('../views/SharedLoanRedirect.vue'),
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

  // Unknown paths must never dead-end in a blank screen — the installed PWA
  // has no address bar to recover from one. Land on the dashboard; its auth
  // guard bounces guests on to /login.
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard',
  },
]

// No `scrollBehavior` here ON PURPOSE. The landing header/footer now render on
// /loans and /originators too, so their «Как работи» / «Въпроси» links carry a
// hash back to «/» — but handing vue-router a scrollBehavior flips
// `history.scrollRestoration` to 'manual' for the WHOLE SPA, and its restore
// fires before async pages (portfolio, transactions) have their rows, which
// would quietly break back-button position everywhere. HomePage.vue scrolls to
// the hash itself instead; the browser keeps owning restoration.
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

// Deploy self-refresh (2026-08-15): when the API reported a NEWER frontend
// build than this tab booted with, reload at the next navigation — the one
// moment a full refresh is guaranteed not to interrupt anything.
router.afterEach(async () => {
  const { isStale } = await import('../utils/buildVersion')
  if (isStale()) {
    window.location.reload()
  }
})

export default router
