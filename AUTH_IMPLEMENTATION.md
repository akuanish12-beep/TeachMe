# JWT Authentication Implementation

## Files Created/Modified

### New Files Created:

1. **src/Application/Helpers/JsonResponse.php**
   - Helper for consistent JSON responses
   - Methods: `success()`, `error()`, `validationError()`

2. **src/Application/Helpers/Validator.php**
   - Input validation helper
   - Methods: `required()`, `email()`, `minLength()`, `maxLength()`

3. **src/Application/Middleware/JwtMiddleware.php**
   - JWT authentication middleware
   - Extracts and verifies Bearer token
   - Injects `user_id` into request attributes

4. **src/Application/Actions/Auth/SignupAction.php**
   - POST /auth/signup handler
   - Validates input, hashes password (PASSWORD_BCRYPT)
   - Creates user and subscription record
   - Returns JWT token

5. **src/Application/Actions/Auth/LoginAction.php**
   - POST /auth/login handler
   - Verifies credentials
   - Returns JWT token

6. **src/Application/Actions/Auth/MeAction.php**
   - GET /auth/me handler (protected)
   - Returns user profile with subscription status

### Modified Files:

1. **app/dependencies.php**
   - Added PDO database connection to DI container

2. **app/routes.php**
   - Added /auth group with signup, login, and me endpoints
   - Applied JwtMiddleware to /auth/me route

## API Endpoints

### 1. POST /auth/signup
**Request:**
```json
{
  "fullName": "Test User",
  "email": "test@example.com",
  "password": "Secret123!"
}
```

**Response (201):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "fullName": "Test User",
    "email": "test@example.com"
  }
}
```

### 2. POST /auth/login
**Request:**
```json
{
  "email": "test@example.com",
  "password": "Secret123!"
}
```

**Response (200):**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user": {
    "id": 1,
    "fullName": "Test User",
    "email": "test@example.com"
  }
}
```

### 3. GET /auth/me
**Headers:**
```
Authorization: Bearer <JWT_TOKEN>
```

**Response (200):**
```json
{
  "id": 1,
  "fullName": "Test User",
  "email": "test@example.com",
  "subscription": {
    "status": "none"
  }
}
```

## JWT Implementation

### Token Structure:
- **Algorithm:** HS256
- **Claims:**
  - `sub`: user_id
  - `email`: user email
  - `iat`: issued at timestamp
  - `exp`: expiration (7 days from issue)
- **Secret:** From JWT_SECRET in .env

### Token Verification:
- Performed by JwtMiddleware
- Extracts Bearer token from Authorization header
- Verifies signature and expiration
- Injects user_id into request for downstream handlers

## Error Handling

### Validation Error (422):
```json
{
  "error": "Validation failed",
  "code": "VALIDATION_ERROR",
  "errors": {
    "fullName": "The fullName field is required",
    "password": "The password must be at least 8 characters"
  }
}
```

### Unauthorized (401):
```json
{
  "error": "unauthorized",
  "code": "UNAUTHORIZED"
}
```

### Invalid Credentials (401):
```json
{
  "error": "Invalid credentials",
  "code": "INVALID_CREDENTIALS"
}
```

### Email Exists (409):
```json
{
  "error": "Email already exists",
  "code": "EMAIL_EXISTS"
}
```

## Test Results

### ✅ Test 1: Signup
```bash
curl -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Test User","email":"test@example.com","password":"Secret123!"}'
```
**Result:** ✓ User created, JWT returned

### ✅ Test 2: Login
```bash
curl -X POST http://localhost:8082/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Secret123!"}'
```
**Result:** ✓ JWT returned

### ✅ Test 3: Get Current User
```bash
curl http://localhost:8082/auth/me \
  -H "Authorization: Bearer <TOKEN>"
```
**Result:** ✓ User data with subscription status returned

### ✅ Test 4: Unauthorized Access
```bash
curl http://localhost:8082/auth/me
```
**Result:** ✓ 401 Unauthorized

### ✅ Test 5: Invalid Token
```bash
curl http://localhost:8082/auth/me \
  -H "Authorization: Bearer invalid_token"
```
**Result:** ✓ 401 Unauthorized

### ✅ Test 6: Wrong Password
```bash
curl -X POST http://localhost:8082/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"WrongPassword"}'
```
**Result:** ✓ 401 Invalid credentials

### ✅ Test 7: Duplicate Email
```bash
curl -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Another","email":"test@example.com","password":"Secret123!"}'
```
**Result:** ✓ 409 Email already exists

### ✅ Test 8: Validation Error
```bash
curl -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"email":"test2@example.com"}'
```
**Result:** ✓ 422 Validation failed

## Database Verification

```sql
SELECT u.id, u.full_name, u.email, s.status 
FROM users u 
LEFT JOIN subscriptions s ON u.id = s.user_id;
```

**Result:**
```
id  full_name   email                 status
1   Test User   test@example.com      none
```

## Security Features

✅ Password hashing with PASSWORD_BCRYPT  
✅ JWT signature verification  
✅ Token expiration (7 days)  
✅ CORS headers configured  
✅ Input validation  
✅ SQL injection prevention (prepared statements)  
✅ Consistent error responses (no info leakage)  

## CORS & Middleware Order

1. **CorsMiddleware** - Sets CORS headers
2. **BodyParsingMiddleware** - Parses JSON request bodies
3. **RoutingMiddleware** - Routes requests
4. **JwtMiddleware** - Applied per-route for protected endpoints

## Integration Notes

- Frontend pages (SignIn/SignUp) remain unchanged
- API endpoints ready to be wired to frontend forms
- Token should be stored in localStorage/sessionStorage
- Include token in Authorization header for protected requests
- Subscription status tracking ready for free/pro logic

