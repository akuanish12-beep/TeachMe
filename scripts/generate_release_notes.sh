#!/bin/bash

# Generate Release Notes for TeachMe API
# Usage: ./scripts/generate_release_notes.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
OUTPUT_FILE="$PROJECT_ROOT/RELEASE_NOTES.md"
VERSION="1.0.0"
RELEASE_DATE=$(date +"%Y-%m-%d")

echo "Generating Release Notes for TeachMe API v$VERSION..."

cat > "$OUTPUT_FILE" << 'HEADER'
# TeachMe API - Release Notes

HEADER

cat >> "$OUTPUT_FILE" << HEADER_VARS
## Version $VERSION
**Release Date:** $RELEASE_DATE

HEADER_VARS

cat >> "$OUTPUT_FILE" << 'BODY'
---

## Overview

TeachMe is an AI-powered language learning platform that generates personalized lessons using Google's Gemini API. This release includes the complete backend API with authentication, subscription management, lesson generation, and user management features.

---

## 🎯 Key Features

### Authentication & Authorization
- JWT-based authentication with 7-day token expiration
- Secure password hashing (BCRYPT)
- User registration and login
- Protected endpoints with middleware

### AI Lesson Generation
- Integration with Google Gemini API (gemini-2.5-flash)
- Strict JSON schema validation for lesson structure
- Retry logic with exponential backoff
- Semantic guards for off-topic content
- Token limit handling

### Subscription Management
- Stripe integration for payment processing
- Free trial: 1 lesson generation per user
- Pro subscription: unlimited generations ($9/month)
- Webhook handling for subscription events
- Checkout session creation

### Lesson Management
- List user lessons with pagination
- Get individual lesson details
- Mark lessons as favorites
- Add personal notes to lessons
- Delete lessons with ownership verification

### Rate Limiting
- Redis-based rate limiting
- IP-based limits for auth endpoints
- User-based limits for protected endpoints
- Configurable limits per route

### Monitoring & Logging
- Monolog integration for application logging
- Metrics logging for 4xx/5xx errors
- Admin statistics endpoint
- Request duration tracking

---

## 📊 API Endpoints

### Authentication
- `POST /auth/signup` - User registration
- `POST /auth/login` - User login
- `GET /auth/me` - Get authenticated user profile
- `GET /auth/status` - Check authentication status (public)

### Lessons
- `POST /lessons/generate` - Generate new lesson (JWT, rate limited)
- `GET /lessons` - List user lessons (JWT)
- `GET /lessons/favorites` - List favorite lessons (JWT)
- `GET /lessons/{id}` - Get lesson details (JWT)
- `PATCH /lessons/{id}` - Update lesson (favorite, notes) (JWT)
- `DELETE /lessons/{id}` - Delete lesson (JWT)

### User
- `GET /users/quota` - Get generation quota info (JWT)

### Subscriptions
- `POST /subscriptions/create-checkout-session` - Create Stripe checkout (JWT)
- `POST /webhooks/stripe` - Stripe webhook handler (no auth)

### Admin
- `GET /admin/stats` - System statistics (JWT)

### AI Diagnostics
- `GET /ai/models` - List available Gemini models
- `GET /ai/ping` - Health check for AI service

### System
- `GET /health` - API health check

---

## 🗄️ Database Schema

### Tables
- `users` - User accounts with authentication
- `subscriptions` - User subscription status
- `lessons` - Generated lessons with content
- `generations` - Tracking of generation attempts

### Indexes
- `idx_lessons_user` on `lessons(user_id, created_at)`
- `idx_generations_user` on `generations(user_id, created_at)`
- `idx_subscriptions_user` on `subscriptions(user_id)`
- `idx_users_email` on `users(email)`

### Migrations
- `001_init.sql` - Initial schema
- `002_indexes_and_production.sql` - Production indexes and optimizations

---

## ⚙️ Configuration

### Required Environment Variables

```bash
# Application
APP_ENV=production|development
APP_DEBUG=true|false
APP_URL=https://teachme.mom
FRONTEND_ORIGIN=https://teachme.mom

# Security
JWT_SECRET=<64-character-hex-string>

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=teachme
DB_USER=teachme_prod
DB_PASS=<secure-password>

# AI Services
GEMINI_API_KEY=<google-gemini-api-key>
GEMINI_MODEL=models/gemini-2.5-flash

# Payment Processing
STRIPE_SECRET_KEY=sk_live_<your-key>
STRIPE_WEBHOOK_SECRET=whsec_<your-secret>
STRIPE_PRICE_ID_PRO_MONTHLY=price_<your-price-id>

# Redis (Rate Limiting & Caching)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

---

## 🚀 Deployment Instructions

### Prerequisites
- PHP 8.1+ with extensions: pdo, pdo_mysql, mbstring, json
- MySQL/MariaDB 5.7+
- Redis 6.0+
- Composer 2.0+
- Apache 2.4+ or Nginx

### Installation Steps

1. **Clone Repository**
   ```bash
   cd /var/www
   git clone <repository-url> teachme-api
   cd teachme-api
   ```

2. **Install Dependencies**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure Environment**
   ```bash
   cp .env.production.template .env
   # Edit .env with production values
   nano .env
   ```

4. **Create Database**
   ```bash
   mysql -u root -p
   CREATE DATABASE teachme CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'teachme_prod'@'localhost' IDENTIFIED BY 'secure-password';
   GRANT ALL PRIVILEGES ON teachme.* TO 'teachme_prod'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```

5. **Run Migrations**
   ```bash
   php bin/migrate.php
   ```

6. **Set Permissions**
   ```bash
   chown -R www-data:www-data storage/logs
   chmod -R 775 storage/logs
   ```

7. **Configure Apache** (see Apache configuration section below)

8. **Enable Redis**
   ```bash
   sudo systemctl enable redis-server
   sudo systemctl start redis-server
   ```

9. **Test Installation**
   ```bash
   curl http://localhost/health
   # Should return: {"ok":true,"ts":"2025-10-29T..."}
   ```

### Apache Configuration

Create `/etc/apache2/sites-available/teachme-api.conf`:

```apache
<VirtualHost *:80>
    ServerName api.teachme.mom
    DocumentRoot /var/www/teachme-api/public

    <Directory /var/www/teachme-api/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/teachme-api-error.log
    CustomLog ${APACHE_LOG_DIR}/teachme-api-access.log combined
</VirtualHost>
```

Enable site and restart Apache:
```bash
sudo a2ensite teachme-api
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## 🔒 Security Features

### Authentication
- JWT tokens with HS256 algorithm
- 64-character minimum secret key
- 7-day token expiration
- BCRYPT password hashing (cost: 12)

### Rate Limiting
- `/auth/signup`: 3 requests/hour per IP
- `/auth/login`: 5 requests/15min per IP
- `/lessons/generate`: 5 requests/hour per user

### CORS
- Restricted to production domain
- Credentials allowed
- Preflight caching: 24 hours

### Input Validation
- Request body validation
- Email format validation
- Password strength requirements
- SQL injection prevention (PDO prepared statements)

---

## 📈 Performance Optimizations

### Database
- Composite indexes on frequently queried columns
- Foreign key constraints with CASCADE
- Connection pooling via PDO persistent connections

### Caching
- Redis-based rate limiting with TTL
- Model list caching (2-second TTL)

### API
- JSON response optimization
- Gzip compression (Apache)
- HTTP/2 support

---

## 🧪 Testing

### Health Check
```bash
curl http://localhost/health
```

### Authentication Flow
```bash
# Signup
curl -X POST http://localhost/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Test User","email":"test@example.com","password":"Test123456"}'

# Login
curl -X POST http://localhost/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Test123456"}'
```

### Lesson Generation
```bash
TOKEN="your-jwt-token"

curl -X POST http://localhost/lessons/generate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"topic":"Colors in Spanish","language":"Spanish"}'
```

---

## 📦 Dependencies

### PHP Packages (Composer)
- slim/slim: ^4.0 - Micro framework
- php-di/php-di: ^7.0 - Dependency injection
- slim/psr7: ^1.0 - PSR-7 implementation
- monolog/monolog: ^3.0 - Logging
- vlucas/phpdotenv: ^5.0 - Environment variables
- firebase/php-jwt: ^6.0 - JWT authentication
- guzzlehttp/guzzle: ^7.0 - HTTP client (Gemini API)
- predis/predis: ^3.2 - Redis client
- stripe/stripe-php: ^10.0 - Stripe integration

### System Requirements
- PHP: 8.1+
- MySQL: 5.7+ or MariaDB 10.3+
- Redis: 6.0+
- Apache: 2.4+ (with mod_rewrite)

---

## 📝 Changelog

### Version 1.0.0 (2025-10-29)

#### Added
- Initial release
- JWT authentication system
- Gemini AI integration for lesson generation
- Stripe subscription management
- Redis-based rate limiting
- Lesson management (CRUD operations)
- Favorites and notes functionality
- Admin statistics endpoint
- Comprehensive error logging
- Health check endpoints
- Database migrations system

#### Security
- CORS configuration
- Rate limiting on all endpoints
- Password hashing with BCRYPT
- JWT token validation
- Input sanitization

#### Performance
- Database indexes optimization
- Redis caching
- Connection pooling
- Query optimization

---

## 🐛 Known Issues

1. **Email Verification**: Not implemented yet (planned for v1.1.0)
2. **Password Reset**: Not implemented yet (planned for v1.1.0)
3. **Admin Role Check**: `/admin/stats` currently accessible by any authenticated user
4. **Stripe Test Mode**: Need to switch to live keys for production

---

## 🔮 Roadmap

### Version 1.1.0 (Planned)
- Email verification system
- Password reset functionality
- Admin role management
- User profile updates
- Lesson sharing (public/private)

### Version 1.2.0 (Planned)
- Multiple AI model support
- Lesson templates
- Progress tracking
- Achievement system
- Social features (comments, ratings)

### Version 2.0.0 (Planned)
- Mobile app API extensions
- Real-time lesson collaboration
- Voice interaction
- Advanced analytics dashboard

---

## 📞 Support

For issues, questions, or contributions:
- GitHub Issues: [Create an issue]
- Documentation: See `BACKEND_API_CONTRACT.md`
- API Reference: See `LESSON_MANAGEMENT_API.md`

---

## 📄 License

Proprietary - All rights reserved

---

## 👥 Credits

- **AI Provider**: Google Gemini API
- **Payment Processing**: Stripe
- **Framework**: Slim Framework 4
- **Cache/Rate Limiting**: Redis

---

**Generated on:** $(date)
**API Version:** 1.0.0
**PHP Version:** $(php -v | head -1)
**Database:** MySQL/MariaDB
BODY

echo "✅ Release notes generated: $OUTPUT_FILE"
echo ""
echo "Summary:"
wc -l "$OUTPUT_FILE" | awk '{print "  Lines:", $1}'
echo ""

