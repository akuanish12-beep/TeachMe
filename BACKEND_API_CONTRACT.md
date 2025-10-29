# Tutorly Backend API Contract

**Version:** 1.0  
**Base URL:** `http://localhost:8082` (development)  
**Production URL:** TBD

## Table of Contents
- [Authentication](#authentication)
- [User Endpoints](#user-endpoints)
- [Lesson Endpoints](#lesson-endpoints)
- [Error Handling](#error-handling)
- [CORS Configuration](#cors-configuration)
- [Lesson JSON Schema](#lesson-json-schema)
- [Frontend Integration Notes](#frontend-integration-notes)

---

## Authentication

All authenticated endpoints require a JWT token in the `Authorization` header:
```
Authorization: Bearer <token>
```

### POST /auth/signup

Create a new user account.

**Request:**
```json
{
  "fullName": "John Doe",
  "email": "john@example.com",
  "password": "SecurePass123!"
}
```

**Response:** `201 Created`
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "user": {
    "id": 1,
    "fullName": "John Doe",
    "email": "john@example.com"
  }
}
```

**Validation Rules:**
- `fullName`: Required, max 120 characters
- `email`: Required, valid email format, unique
- `password`: Required, min 6 characters

**Error Responses:**
- `422 Unprocessable Entity` - Validation failed
- `409 Conflict` - Email already exists

---

### POST /auth/login

Authenticate and receive JWT token.

**Request:**
```json
{
  "email": "john@example.com",
  "password": "SecurePass123!"
}
```

**Response:** `200 OK`
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
}
```

**JWT Claims:**
- `sub`: User ID
- `email`: User email
- `iat`: Issued at timestamp
- `exp`: Expiration (7 days from issue)

**Error Responses:**
- `401 Unauthorized` - Invalid credentials
- `422 Unprocessable Entity` - Validation failed

---

### GET /auth/me

Get current user profile. **[Requires Auth]**

**Request:**
```bash
GET /auth/me
Authorization: Bearer <token>
```

**Response:** `200 OK`
```json
{
  "id": 1,
  "fullName": "John Doe",
  "email": "john@example.com",
  "subscription": {
    "status": "none"
  }
}
```

**Subscription Status Values:**
- `none` - No active subscription (free trial available)
- `active` - Pro subscription active
- `canceled` - Subscription canceled
- `past_due` - Payment failed

**Error Responses:**
- `401 Unauthorized` - Invalid or expired token

---

## User Endpoints

### GET /users/quota

Check user's generation quota and subscription status. **[Requires Auth]**

**Request:**
```bash
GET /users/quota
Authorization: Bearer <token>
```

**Response:** `200 OK`
```json
{
  "freeGenerationsUsed": 0,
  "freeGenerationsLimit": 1,
  "hasActiveSubscription": false,
  "canGenerate": true
}
```

**Response Fields:**
- `freeGenerationsUsed`: Number of free generations used (0-∞)
- `freeGenerationsLimit`: Always 1 (one free trial)
- `hasActiveSubscription`: `true` if subscription status is "active"
- `canGenerate`: `true` if user can generate (has subscription OR hasn't used free trial)

**Use Cases:**
- Check before showing "Generate" button
- Determine whether to show pricing popup
- Display remaining free trials

**Error Responses:**
- `401 Unauthorized` - Not authenticated

---

## Lesson Endpoints

### POST /lessons/generate

Generate a new language learning lesson using AI. **[Requires Auth]**

**Request:**
```json
{
  "topic": "Basic Spanish Greetings",
  "language": "Spanish"
}
```

**Validation Rules:**
- `topic`: Required, max 255 characters
- `language`: Required, max 80 characters

**Response:** `201 Created`
```json
{
  "id": 1,
  "lesson": {
    "topic": "Basic Spanish Greetings",
    "language": "Spanish",
    "title": "Introduction to Spanish Greetings",
    "sections": [
      {
        "heading": "Basic Greetings",
        "body": "In Spanish, the most common greeting is \"Hola\"..."
      }
    ],
    "exercises": {
      "fill_in_the_blanks": [
        {
          "prompt": "Complete the greeting",
          "text_with_gaps": "___ días",
          "answers": ["Buenos"]
        }
      ],
      "translate_phrase": [
        {
          "prompt": "Translate to Spanish",
          "source": "Good morning",
          "target_hint": "Buenos ___"
        }
      ],
      "answer_question": [
        {
          "prompt": "Answer in your own words",
          "question": "¿Cuándo usas \"Buenos días\"?",
          "expected_points": ["In the morning", "As a formal greeting"]
        }
      ]
    }
  }
}
```

**Quota Logic:**
1. **If user has active subscription** → Always allowed
2. **If no subscription:**
   - First generation (0 previous) → Allowed (free trial)
   - Subsequent generations → **402 Payment Required**

**Error Responses:**

**402 Payment Required** - Free trial exhausted:
```http
HTTP/1.1 402 Payment Required
X-Reason: payment_required
Content-Type: application/json
```
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

**Other Errors:**
- `401 Unauthorized` - Not authenticated
- `422 Unprocessable Entity` - Validation failed
- `500 Internal Server Error` - Generation failed

---

### GET /lessons

List user's generated lessons. **[Requires Auth]**

**Request:**
```bash
GET /lessons?page=1&pageSize=20
Authorization: Bearer <token>
```

**Query Parameters:**
- `page` (optional): Page number, default `1`, min `1`
- `pageSize` (optional): Items per page, default `20`, min `1`, max `50`

**Response:** `200 OK`
```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Total-Count: 42
```
```json
[
  {
    "id": 3,
    "title": "Introduction to French Vocabulary",
    "topic": "French Basics",
    "language": "French",
    "created_at": "2025-10-29T17:30:00Z"
  },
  {
    "id": 2,
    "title": "Spanish Greetings and Introductions",
    "topic": "Spanish Basics",
    "language": "Spanish",
    "created_at": "2025-10-29T16:45:00Z"
  },
  {
    "id": 1,
    "title": "Introduction to Spanish Greetings",
    "topic": "Basic Greetings",
    "language": "Spanish",
    "created_at": "2025-10-29T15:20:00Z"
  }
]
```

**Response Headers:**
- `X-Total-Count`: Total number of lessons (for pagination UI)

**Ordering:** Always `created_at DESC` (newest first)

**Error Responses:**
- `401 Unauthorized` - Not authenticated

---

### GET /lessons/{id}

Get full lesson content by ID. **[Requires Auth]**

**Request:**
```bash
GET /lessons/1
Authorization: Bearer <token>
```

**Response:** `200 OK`
```json
{
  "id": 1,
  "title": "Introduction to Spanish Greetings",
  "topic": "Basic Spanish Greetings",
  "language": "Spanish",
  "created_at": "2025-10-29T15:20:00Z",
  "content": {
    "topic": "Basic Spanish Greetings",
    "language": "Spanish",
    "title": "Introduction to Spanish Greetings",
    "sections": [
      {
        "heading": "Basic Greetings",
        "body": "In Spanish, the most common greeting..."
      },
      {
        "heading": "Asking How Someone Is",
        "body": "After greeting someone, it's polite..."
      }
    ],
    "exercises": {
      "fill_in_the_blanks": [ /* ... */ ],
      "translate_phrase": [ /* ... */ ],
      "answer_question": [ /* ... */ ]
    }
  }
}
```

**Authorization:** User can only access their own lessons

**Error Responses:**
- `401 Unauthorized` - Not authenticated
- `404 Not Found` - Lesson doesn't exist or doesn't belong to user

---

## Error Handling

### Standard Error Response Format

```json
{
  "error": "error_code",
  "message": "Human-readable error message"
}
```

### HTTP Status Codes

| Code | Meaning | When Used |
|------|---------|-----------|
| `200` | OK | Successful GET request |
| `201` | Created | Resource created successfully |
| `401` | Unauthorized | Missing or invalid JWT token |
| `402` | Payment Required | Free trial used, upgrade needed |
| `404` | Not Found | Resource doesn't exist |
| `409` | Conflict | Duplicate resource (e.g., email exists) |
| `422` | Unprocessable Entity | Validation failed |
| `500` | Internal Server Error | Server-side error |

### Common Error Codes

- `UNAUTHORIZED` - Authentication required or failed
- `PAYMENT_REQUIRED` - Quota exceeded, upgrade needed
- `NOT_FOUND` - Resource not found
- `VALIDATION_ERROR` - Input validation failed
- `GENERATION_FAILED` - AI generation failed

### Validation Error Response

```json
{
  "error": "Validation failed",
  "errors": {
    "email": ["Email is required", "Email must be valid"],
    "password": ["Password must be at least 6 characters"]
  }
}
```

---

## CORS Configuration

**Allowed Origins:**
- Development: `http://localhost:5173`
- Production: TBD

**Allowed Methods:**
```
GET, POST, PUT, DELETE, OPTIONS
```

**Allowed Headers:**
```
Content-Type, Authorization, X-Requested-With
```

**Credentials:** Allowed (`Access-Control-Allow-Credentials: true`)

**Preflight Cache:** 24 hours (`Access-Control-Max-Age: 86400`)

---

## Lesson JSON Schema

All generated lessons conform to this strict schema:

```json
{
  "topic": "string - lesson topic",
  "language": "string - target language",
  "title": "string - lesson title",
  "sections": [
    {
      "heading": "string - section heading",
      "body": "string - section content"
    }
  ],
  "exercises": {
    "fill_in_the_blanks": [
      {
        "prompt": "string - instruction",
        "text_with_gaps": "string - text with ___ for blanks",
        "answers": ["array", "of", "correct", "answers"]
      }
    ],
    "translate_phrase": [
      {
        "prompt": "string - instruction",
        "source": "string - phrase to translate",
        "target_hint": "string - partial translation hint"
      }
    ],
    "answer_question": [
      {
        "prompt": "string - instruction",
        "question": "string - question text",
        "expected_points": ["array", "of", "key", "points"]
      }
    ]
  }
}
```

**Schema Validation:**
- All lessons are validated against this schema before storage
- Invalid lessons trigger retry logic (up to 3 attempts)
- Mock mode (when `GEMINI_API_KEY=PLACEHOLDER_TO_BE_FILLED`) returns valid schema

**Required Fields:**
- `topic`, `language`, `title` - Always present
- `sections` - At least 1 section with `heading` and `body`
- `exercises` - Object with all 3 exercise types (can be empty arrays)

---

## Frontend Integration Notes

### Authentication Flow

```typescript
// 1. Check if user is authenticated
const token = localStorage.getItem('token');
if (!token) {
  // Redirect to /signup
  window.location.href = '/signup';
  return;
}

// 2. Set authorization header for all requests
fetch('/api/endpoint', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  }
});
```

### Handling 401 Unauthorized

**Action:** Redirect to `/signup`

```typescript
if (response.status === 401) {
  localStorage.removeItem('token');
  window.location.href = '/signup';
  return;
}
```

### Handling 402 Payment Required

**Action:** Open pricing popup/modal

```typescript
if (response.status === 402) {
  const data = await response.json();
  showPricingModal({
    message: data.message,
    price: data.upgrade.price,
    currency: data.upgrade.currency,
    plan: data.upgrade.plan
  });
  return;
}
```

### Pre-Generation Quota Check

**Best Practice:** Check quota before allowing generation UI

```typescript
async function checkCanGenerate() {
  const response = await fetch('/users/quota', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  
  if (response.status === 401) {
    window.location.href = '/signup';
    return false;
  }
  
  const { canGenerate } = await response.json();
  
  if (!canGenerate) {
    // Proactively show pricing modal
    showPricingModal();
    return false;
  }
  
  return true;
}

// Before showing generate button
if (await checkCanGenerate()) {
  showGenerateButton();
} else {
  showUpgradeButton();
}
```

### Pagination Example

```typescript
async function loadLessons(page = 1, pageSize = 20) {
  const response = await fetch(
    `/lessons?page=${page}&pageSize=${pageSize}`,
    { headers: { 'Authorization': `Bearer ${token}` } }
  );
  
  const lessons = await response.json();
  const totalCount = parseInt(response.headers.get('X-Total-Count') || '0');
  const totalPages = Math.ceil(totalCount / pageSize);
  
  return { lessons, totalCount, totalPages };
}
```

### Error Display Helper

```typescript
function handleApiError(response: Response, data: any) {
  switch (response.status) {
    case 401:
      window.location.href = '/signup';
      break;
    case 402:
      showPricingModal(data.message, data.upgrade);
      break;
    case 422:
      showValidationErrors(data.errors);
      break;
    case 404:
      showNotification('Resource not found', 'error');
      break;
    default:
      showNotification(data.message || 'An error occurred', 'error');
  }
}
```

---

## Quick Reference

### Endpoint Summary

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/auth/signup` | POST | No | Create account |
| `/auth/login` | POST | No | Login |
| `/auth/me` | GET | Yes | Get profile |
| `/users/quota` | GET | Yes | Check generation quota |
| `/lessons/generate` | POST | Yes | Generate lesson |
| `/lessons` | GET | Yes | List lessons |
| `/lessons/{id}` | GET | Yes | Get lesson by ID |

### Status Code Quick Reference

- `200/201` ✅ Success
- `401` 🔒 Redirect to `/signup`
- `402` 💰 Show pricing popup
- `404` ❓ Not found
- `422` ⚠️ Validation error
- `500` 💥 Server error

---

**Last Updated:** 2025-10-29  
**Maintained By:** Backend Team  
**Questions?** Contact: dev@tutorly.space

