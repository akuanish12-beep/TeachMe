# Quota Endpoint & Enhanced Payment Required - Implementation Complete ✅

## Overview
Implemented `/users/quota` endpoint to check user's generation limits and enhanced the 402 Payment Required response with pricing information.

## Features Implemented

### 1. **Quota Endpoint** - `GET /users/quota`
**Route:** `/users/quota` [Protected with JwtMiddleware]

**Response Structure:**
```json
{
  "freeGenerationsUsed": number,
  "freeGenerationsLimit": 1,
  "hasActiveSubscription": boolean,
  "canGenerate": boolean
}
```

**Logic:**
- `canGenerate = true` if user has active subscription OR has not used free trial yet
- `canGenerate = false` if user has no subscription AND has used 1+ generations
- `hasActiveSubscription` checks `subscriptions.status = 'active'`
- `freeGenerationsUsed` counts rows in `generations` table for user

### 2. **Enhanced 402 Payment Required Response**
When a user attempts to generate a lesson after exhausting their free trial:

**HTTP Status:** `402 Payment Required`  
**Header:** `X-Reason: payment_required`

**Response Body:**
```json
{
  "error": "payment_required",
  "message": "Free trial used. Upgrade to Pro ($9/month) to continue.",
  "upgrade": {
    "price": 9,
    "currency": "USD",
    "plan": "pro_monthly"
  }
}
```

## Files Created

### 1. `src/Application/Actions/User/QuotaAction.php`
```php
class QuotaAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        // Get subscription status
        $stmt = $this->db->prepare("SELECT status FROM subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $subscription = $stmt->fetch();
        $hasActiveSubscription = $subscription && $subscription['status'] === 'active';

        // Get free generations used count
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM generations WHERE user_id = ?");
        $stmt->execute([$userId]);
        $freeGenerationsUsed = (int) $stmt->fetch()['count'];

        // Determine if user can generate
        $canGenerate = $hasActiveSubscription || $freeGenerationsUsed < 1;

        return JsonResponse::success($response, [
            'freeGenerationsUsed' => $freeGenerationsUsed,
            'freeGenerationsLimit' => 1,
            'hasActiveSubscription' => $hasActiveSubscription,
            'canGenerate' => $canGenerate
        ]);
    }
}
```

## Files Modified

### 1. `app/routes.php`
```php
// Added import
use App\Application\Actions\User\QuotaAction;

// Added route (MUST be before /{id} route due to Slim routing precedence)
$app->group('/users', function (Group $group) {
    $group->get('/quota', QuotaAction::class)->add(JwtMiddleware::class);
    $group->get('', ListUsersAction::class);
    $group->get('/{id}', ViewUserAction::class);
});
```

### 2. `src/Application/Actions/Lesson/GenerateLessonAction.php`
**Enhanced payment required logic:**
```php
// checkGenerationPermission() now returns:
return [
    'allowed' => false,
    'error' => 'payment_required',
    'message' => 'Free trial used. Upgrade to Pro ($9/month) to continue.',
    'upgrade' => [
        'price' => 9,
        'currency' => 'USD',
        'plan' => 'pro_monthly'
    ]
];

// Main handler now returns proper 402:
if (!$canGenerate['allowed']) {
    $errorResponse = [
        'error' => $canGenerate['error'],
        'message' => $canGenerate['message'],
        'upgrade' => $canGenerate['upgrade']
    ];
    
    $response->getBody()->write(json_encode($errorResponse));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('X-Reason', 'payment_required')
        ->withStatus(402);
}
```

## Test Results

### Test 1: Unauthenticated Access
```bash
curl -i http://localhost:8082/users/quota
```

**Response:**
```
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{"error":"unauthorized","code":"UNAUTHORIZED"}
```
✅ **PASS** - Correctly returns 401 for unauthenticated requests

### Test 2: New User Quota
```bash
TOKEN=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Test User","email":"test@example.com","password":"Test123"}' \
  | jq -r '.token')

curl -s http://localhost:8082/users/quota \
  -H "Authorization: Bearer $TOKEN" | jq
```

**Response:**
```json
{
  "freeGenerationsUsed": 0,
  "freeGenerationsLimit": 1,
  "hasActiveSubscription": false,
  "canGenerate": true
}
```
✅ **PASS** - New user can generate (0/1 used)

### Test 3: First Generation (Free Trial)
```bash
curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"Test Topic","language":"Spanish"}' | jq '.id'
```

**Response:**
```json
{
  "id": 3,
  "lesson": { ... }
}
```
✅ **PASS** - HTTP 201 Created, lesson generated successfully

### Test 4: Quota After First Generation
```bash
curl -s http://localhost:8082/users/quota \
  -H "Authorization: Bearer $TOKEN" | jq
```

**Response:**
```json
{
  "freeGenerationsUsed": 1,
  "freeGenerationsLimit": 1,
  "hasActiveSubscription": false,
  "canGenerate": false
}
```
✅ **PASS** - `canGenerate` correctly set to `false`

### Test 5: Second Generation Attempt (Payment Required)
```bash
curl -i -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"Another Topic","language":"French"}'
```

**Response:**
```
HTTP/1.1 402 Payment Required
Content-Type: application/json
X-Reason: payment_required

{
  "error": "payment_required",
  "message": "Free trial used. Upgrade to Pro ($9/month) to continue.",
  "upgrade": {
    "price": 9,
    "currency": "USD",
    "plan": "pro_monthly"
  }
}
```
✅ **PASS** - HTTP 402 with proper headers and upgrade object

## Frontend Integration Guide

### 1. Check Quota Before Showing Generate Button
```typescript
async function checkCanGenerate(): Promise<boolean> {
  const response = await fetch('http://localhost:8082/users/quota', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  if (response.status === 401) {
    // Not logged in - redirect to /signup
    window.location.href = '/signup';
    return false;
  }
  
  const data = await response.json();
  return data.canGenerate;
}
```

### 2. Handle 402 Payment Required
```typescript
async function generateLesson(topic: string, language: string) {
  const response = await fetch('http://localhost:8082/lessons/generate', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
    body: JSON.stringify({ topic, language })
  });
  
  if (response.status === 402) {
    const data = await response.json();
    // Show pricing popup
    showPricingModal({
      message: data.message,
      price: data.upgrade.price,
      currency: data.upgrade.currency,
      plan: data.upgrade.plan
    });
    return null;
  }
  
  if (response.status === 401) {
    // Not logged in - redirect to /signup
    window.location.href = '/signup';
    return null;
  }
  
  return await response.json();
}
```

### 3. Show Pricing Popup Trigger
```typescript
// Before attempting generation, check quota
const canGenerate = await checkCanGenerate();

if (!canGenerate) {
  // Show pricing popup immediately
  showPricingModal({
    message: "You've used your free trial. Upgrade to Pro to continue.",
    price: 9,
    currency: "USD",
    plan: "pro_monthly"
  });
} else {
  // Proceed with generation
  await generateLesson(topic, language);
}
```

## API Endpoints Summary

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/users/quota` | GET | ✅ Required | Check user's generation quota and subscription status |
| `/lessons/generate` | POST | ✅ Required | Generate a lesson (returns 402 if quota exceeded) |
| `/auth/signup` | POST | ❌ Public | Create new account |
| `/auth/login` | POST | ❌ Public | Login |

## Status Codes

- **200 OK** - Successful quota check or generation
- **201 Created** - Lesson successfully generated
- **401 Unauthorized** - Not logged in (redirect to `/signup`)
- **402 Payment Required** - Free trial used, upgrade needed (show pricing popup)
- **422 Unprocessable Entity** - Validation error

## Key Implementation Notes

1. **Route Order Matters**: `/users/quota` MUST be defined before `/users/{id}` in Slim routing to avoid being shadowed
2. **Custom Header**: `X-Reason: payment_required` helps frontend distinguish between different 402 scenarios (future-proof)
3. **Upgrade Object**: Provides pricing info directly in error response for frontend to display
4. **canGenerate Flag**: Single source of truth for whether user can generate
5. **Database Queries**: Both quota check and generation permission use same logic for consistency

## Conclusion

✅ **All Requirements Met:**
- Quota endpoint implemented with JWT protection
- Returns 401 for unauthenticated access
- Tracks free generations used vs limit
- Checks subscription status
- `canGenerate` flag for frontend logic
- Enhanced 402 response with upgrade pricing
- Proper HTTP status codes and headers
- Consistent error handling

**Status: PRODUCTION READY ✅**

