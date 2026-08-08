# EDURA Sprint 1 SaaS Implementation

## Overview

Institution is the SaaS tenant. No `tenants` table is introduced.

Sprint 1 adds the production foundation for:

- Subscription plans
- SaaS subscriptions
- Database-driven features
- Institution settings
- AI credit ledger
- Usage statistics

Existing `institution_subscriptions` remains available for backward compatibility.

## API Documentation

All endpoints require `auth:sanctum`.

### Subscription Plans

`GET /api/subscription-plans`

Super Admin and Institution Admin can list plans. Institution Admin receives active plans only.

`POST /api/subscription-plans`

Super Admin only.

`GET /api/subscription-plans/{subscriptionPlan}`

Super Admin can view all. Institution Admin can view active plans.

`PATCH /api/subscription-plans/{subscriptionPlan}`

Super Admin only.

`DELETE /api/subscription-plans/{subscriptionPlan}`

Super Admin only.

### Subscriptions

`GET /api/subscriptions`

Super Admin can list all. Institution Admin is scoped to their institution.

`POST /api/subscriptions`

Super Admin only.

`GET /api/subscriptions/{subscription}`

Super Admin or owning Institution Admin.

`PATCH /api/subscriptions/{subscription}`

Super Admin only.

`DELETE /api/subscriptions/{subscription}`

Super Admin only.

Lifecycle endpoints:

- `POST /api/subscriptions/{subscription}/activate`
- `POST /api/subscriptions/{subscription}/suspend`
- `POST /api/subscriptions/{subscription}/cancel`
- `POST /api/subscriptions/{subscription}/expire`

All lifecycle endpoints are Super Admin only.

### Features

`GET /api/features`

Super Admin and Institution Admin can list features. Institution Admin receives active features only.

`POST /api/features`

Super Admin only.

`GET /api/features/{feature}`

Super Admin or Institution Admin for active features.

`PATCH /api/features/{feature}`

Super Admin only.

`DELETE /api/features/{feature}`

Super Admin only.

### Plan Features

`GET /api/plan-features`

Super Admin and Institution Admin can list plan features. Institution Admin receives active enabled records only.

`POST /api/plan-features`

Super Admin only.

`GET /api/plan-features/{planFeature}`

Super Admin or Institution Admin for active records.

`PATCH /api/plan-features/{planFeature}`

Super Admin only.

`DELETE /api/plan-features/{planFeature}`

Super Admin only.

### Institution Settings

`GET /api/institution-settings`

Super Admin can list all or filter with `institution_id` and `group`. Institution Admin is scoped to their own institution.

`POST /api/institution-settings`

Super Admin or owning Institution Admin.

`GET /api/institution-settings/{institutionSetting}`

Super Admin or owning Institution Admin.

`PATCH /api/institution-settings/{institutionSetting}`

Super Admin or owning Institution Admin.

`DELETE /api/institution-settings/{institutionSetting}`

Super Admin or owning Institution Admin.

Encrypted setting values are masked in API responses.

### AI Credit Transactions

`GET /api/ai-credit-transactions`

Super Admin can list all. Institution Admin is scoped to their own institution.

`POST /api/ai-credit-transactions`

Super Admin only.

`GET /api/ai-credit-transactions/{aiCreditTransaction}`

Super Admin or owning Institution Admin.

AI credit transactions are ledger entries and are not updateable or deleteable through API.

### Usage Statistics

`GET /api/usage-statistics`

Super Admin can list all. Institution Admin is scoped to their own institution.

`POST /api/usage-statistics`

Super Admin only.

`GET /api/usage-statistics/{usageStatistic}`

Super Admin or owning Institution Admin.

`PATCH /api/usage-statistics/{usageStatistic}`

Super Admin only.

`DELETE /api/usage-statistics/{usageStatistic}`

Super Admin only.

## Developer Notes

Business modules must not check plan names.

Use:

```php
FeatureService::enabled($institution, 'feature_code');
UsageStatisticsService::assertWithinLimit($institution, 'students');
AICreditService::consume([...]);
InstitutionSettingsService::get($institution, 'branding', 'primary_color');
```

Services throw `App\Exceptions\DomainException` for domain failures. API rendering converts these to `422` responses.

Controllers must remain thin. Do not move subscription lifecycle, feature checks, AI credit balance logic, or usage limit enforcement into controllers.

## Deployment Notes

Run migrations:

```bash
php artisan migrate
```

Run seeders when deploying to a fresh environment:

```bash
php artisan db:seed
```

Clear optimized caches after deployment:

```bash
php artisan optimize:clear
```

## Performance Notes

Indexes are added for tenant-scoped subscription, feature, settings, AI credit, and usage queries.

Future optimization:

- Cache feature resolution per institution and subscription version.
- Cache institution settings by `institution_id` and `group`.
- Invalidate caches when subscriptions, plan features, or institution settings change.

## Security Notes

Policies protect every SaaS endpoint.

Institution Admin access is tenant-scoped through `institution_users`.

Encrypted settings are stored encrypted and masked in API responses.

AI credits are append-only ledger records and cannot be updated or deleted through the API.
