# API Endpoint Tests - Quick Reference

## Setup
```bash
# Start server
cd /home/daninvestor/tutorly.space/TutorlyAPI/app/backend
php -S localhost:8082 -t public &
```

## 1. Health Check
```bash
curl http://localhost:8082/health
```
**Expected:** `{"ok":true,"ts":"..."}`

## 2. Sign Up
```bash
curl -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Test User","email":"test@example.com","password":"Test123456"}'
```
**Expected:** `{"token":"...","user":{...}}`

## 3. Login
```bash
curl -X POST http://localhost:8082/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Test123456"}'
```
**Expected:** `{"token":"..."}`

## 4. Get User Profile
```bash
TOKEN="your_token_here"
curl http://localhost:8082/auth/me \
  -H "Authorization: Bearer $TOKEN"
```
**Expected:** `{"id":1,"fullName":"...","email":"...","subscription":{...}}`

## 5. Check Quota (Unauthenticated)
```bash
curl -i http://localhost:8082/users/quota
```
**Expected:** HTTP 401 Unauthorized

## 6. Check Quota (Authenticated)
```bash
curl http://localhost:8082/users/quota \
  -H "Authorization: Bearer $TOKEN"
```
**Expected:** 
```json
{
  "freeGenerationsUsed": 0,
  "freeGenerationsLimit": 1,
  "hasActiveSubscription": false,
  "canGenerate": true
}
```

## 7. Generate First Lesson (Free Trial)
```bash
curl -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"Spanish Basics","language":"Spanish"}'
```
**Expected:** HTTP 201 Created with lesson JSON

## 8. Check Quota After Generation
```bash
curl http://localhost:8082/users/quota \
  -H "Authorization: Bearer $TOKEN"
```
**Expected:** 
```json
{
  "freeGenerationsUsed": 1,
  "freeGenerationsLimit": 1,
  "hasActiveSubscription": false,
  "canGenerate": false
}
```

## 9. Try Second Generation (Payment Required)
```bash
curl -i -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"French Basics","language":"French"}'
```
**Expected:** HTTP 402 Payment Required
```
HTTP/1.1 402 Payment Required
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

## 10. List User's Lessons
```bash
curl http://localhost:8082/lessons \
  -H "Authorization: Bearer $TOKEN"
```
**Expected:** 
```json
{
  "lessons": [...],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "pages": 1
  }
}
```

## 11. Get Specific Lesson
```bash
curl http://localhost:8082/lessons/1 \
  -H "Authorization: Bearer $TOKEN"
```
**Expected:** Full lesson JSON with content

## Frontend Integration Examples

### Check if user can generate before showing UI
```typescript
const response = await fetch('/users/quota', {
  headers: { 'Authorization': `Bearer ${token}` }
});

if (response.status === 401) {
  // Redirect to signup
  window.location.href = '/signup';
} else {
  const { canGenerate } = await response.json();
  if (!canGenerate) {
    // Show pricing modal
    showPricingModal();
  }
}
```

### Handle 402 during generation
```typescript
const response = await fetch('/lessons/generate', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`
  },
  body: JSON.stringify({ topic, language })
});

if (response.status === 402) {
  const { message, upgrade } = await response.json();
  showPricingModal(message, upgrade.price);
}
```
