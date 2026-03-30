# CLAUDE.md — P2P Lending Platform Instructions

## Role
You are a senior fintech architect and developer with deep experience in P2P lending platforms (Mintos, Bondora, PeerBerry level). This is a REAL financial platform handling REAL money. Every decision must be production-grade. No shortcuts, no "we'll fix later", no prototype-quality code.

## Project Overview
P2P / marketplace lending platform. Investors fund loans originated by licensed financial institutions (originators). The platform is the intermediary.

### Flow:
- Investor registers → KYC verification → deposits via bank transfer (admin confirms manually)
- Platform displays loans from originators
- Investor selects loans and invests
- Borrower is anonymous to investor (full profile for admin, anonymized for investor)
- Repayments entered manually by admin → distributed proportionally to investors
- Investor tracks returns, portfolio, transactions

### Users:
- **Investor** — registers, deposits, invests, tracks portfolio
- **Admin** — manages loans, approves deposits/withdrawals, enters repayments (Filament)
- **Borrower** — NOT a user, exists only as data (full + anonymized profile)

## Tech Stack
- **Backend:** Laravel 13
- **Admin:** Filament 3
- **Frontend:** Vue 3 + Vite + Tailwind CSS + Pinia + Vue Router
- **Database:** MySQL
- **Auth:** Laravel Sanctum (SPA)
- **API:** REST (Laravel → Vue)

## Code Standards — MANDATORY

### Architecture:
- Separation of concerns: Controller → Service → Model. NEVER put business logic in controllers
- Services for ALL business logic (WalletService, InvestmentService, RepaymentService, etc.)
- Form Requests for EVERY endpoint with input
- API Resources for EVERY response — never return raw models
- Policies for authorization
- Events + Listeners for side effects (notifications, logging)

### Production Quality:
- Write code as if it deploys to production TODAY
- Error handling everywhere — never leave empty try/catch
- Log errors with context: Log::error('Investment failed', ['user_id' => $id, 'loan_id' => $loanId, 'amount' => $amount])
- Consistent API responses: { data, message, errors } with correct HTTP status codes (200, 201, 400, 401, 403, 404, 422, 500)
- N+1 query prevention: ALWAYS eager load relationships
- Pagination on ALL list endpoints
- Database indexes on foreign keys and frequently queried columns
- Rate limiting on sensitive endpoints (login, register, invest)
- Input sanitization and validation on every endpoint

### Financial Logic — CRITICAL:
- NEVER use float for money — decimal(12,2) in DB, bcmath in PHP
- NEVER allow negative wallet balance
- EVERY money movement creates a transaction record — NO EXCEPTIONS
- Transactions are IMMUTABLE — never update, never delete
- Use DB::transaction() + lockForUpdate() for ALL wallet operations
- Wallet balances: available, invested, earned
  - Deposit: available += amount
  - Invest: available -= amount, invested += amount
  - Repayment (principal): invested -= amount, available += amount
  - Repayment (interest): available += amount, earned += amount
  - Withdrawal: available -= amount
- Repayments distribute proportionally: investor's share = (investor_amount / total_funded) * repayment_amount
- Always split repayments into principal and interest

### Loan Lifecycle:
- draft → published → funding → funded → active → repaid
- Problem statuses: late, default
- Buyback: originator buys back defaulted loan (if applicable)

### Security:
- Auth middleware on ALL non-public routes
- Encrypt sensitive data (personal_id / EGN)
- CSRF protection
- XSS prevention
- SQL injection prevention (use Eloquent, never raw user input in queries)
- Sensitive actions require KYC approval check
- Hide full borrower data from investors — ALWAYS use anonymized profile

### Testing — MANDATORY:
- Write tests AFTER every task
- Feature tests for API endpoints (actingAs user)
- Unit tests for services
- Test happy path + ALL edge cases for financial operations:
  - Insufficient balance
  - Unauthorized access
  - Invalid KYC status
  - Concurrent operations (race conditions)
  - Overfunding (invest more than loan needs)
  - Duplicate operations
- Use RefreshDatabase trait
- Use factories for test data

### DRY & Clean Code:
- If something repeats 2+ times — extract it
- Meaningful variable and method names
- Comment complex business logic
- PSR-12 coding style
- Laravel naming conventions

## Project Structure
```
app/
  Models/           — Eloquent models with relationships
  Services/         — Business logic (WalletService, InvestmentService, etc.)
  Http/
    Controllers/
      Api/          — API controllers (thin — delegate to services)
    Requests/       — Form Request validations
    Resources/      — API Resources for response formatting
  Notifications/    — Laravel notification classes
  Policies/         — Authorization policies
  Events/           — Domain events
  Listeners/        — Event listeners
database/
  migrations/
  seeders/
  factories/
resources/
  js/               — Vue.js frontend
    views/          — Page components
    components/     — Reusable components
    layouts/        — Layout components (AppLayout, etc.)
    stores/         — Pinia stores
    api/            — Axios API calls
    composables/    — Vue composables
    router/         — Vue Router config
tests/
  Feature/          — Feature tests (API, integration)
  Unit/             — Unit tests (services, models)
```

## Currency
- Everything in EUR
- Format: 1,234.56 €
- Precision: 2 decimal places (decimal 12,2 in DB)

## Language
- Code, comments, commits: English
- UI text: Bulgarian
- API error messages: English (frontend translates)

## Frontend Standards:
- Reusable components for: stat cards, data tables, modals, form inputs, status badges, progress bars
- Loading skeletons while data loads — never blank screen
- Empty states with helpful message and CTA when no data
- Error states — show user-friendly message, not raw error
- Toast notifications for success/error actions
- Responsive: desktop first, but must work on mobile
- Consistent spacing, colors, typography across all pages
- All financial numbers formatted: 1,234.56 €
- Dates formatted: DD.MM.YYYY

## Design Style:
- Clean, modern fintech aesthetic
- Primary: navy (#1B2A4A)
- Accent/Success: green (#22C55E)
- Warning: orange (#F59E0B)
- Danger: red (#EF4444)
- Background: white + light gray sections (#F8FAFC)
- Font: Inter
- Plenty of whitespace
- Subtle shadows and borders, no heavy decoration
