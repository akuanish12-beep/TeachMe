# Tutorly API - Production Deployment Checklist

## Pre-Deployment Checklist

### ✅ Configuration
- [ ] Update `.env` with production values:
  - [ ] `APP_ENV=production`
  - [ ] `APP_DEBUG=false`
  - [ ] `FRONTEND_ORIGIN=https://tutorly.space`
  - [ ] `APP_URL=https://tutorly.space`
- [ ] Verify JWT secret is ≥64 characters
- [ ] Add real Stripe keys (currently placeholders):
  - [ ] `STRIPE_SECRET_KEY=sk_live_...`
  - [ ] `STRIPE_WEBHOOK_SECRET=whsec_...`
  - [ ] `STRIPE_PRICE_ID_PRO_MONTHLY=price_...`
- [ ] Verify Gemini API key is production key
- [ ] Set secure database password (not default)

### ✅ Database
- [ ] Run migrations: `php bin/migrate.php`
- [ ] Verify indexes exist:
  ```sql
  SHOW INDEX FROM lessons WHERE Key_name = 'idx_lessons_user';
  SHOW INDEX FROM generations WHERE Key_name = 'idx_generations_user';
  SHOW INDEX FROM subscriptions WHERE Key_name = 'idx_subscriptions_user';
  ```
- [ ] Create production database user with limited privileges
- [ ] Set up automated database backups

### ✅ Security
- [ ] Remove test routes from `app/routes.php`
- [ ] Review logs for sensitive data exposure
- [ ] Enable HTTPS/SSL certificate
- [ ] Configure firewall rules (allow 80, 443, deny others)
- [ ] Set restrictive file permissions:
  ```bash
  chown -R www-data:www-data /var/www/tutorly-api
  chmod -R 755 /var/www/tutorly-api
  chmod -R 775 storage/logs
  chmod 600 .env
  ```

### ✅ Performance
- [ ] Enable Redis persistence (AOF or RDB)
- [ ] Configure Apache/Nginx for production:
  - [ ] Enable gzip compression
  - [ ] Set cache headers
  - [ ] Enable HTTP/2
  - [ ] Configure rate limiting
- [ ] Optimize PHP settings:
  - [ ] `opcache.enable=1`
  - [ ] `memory_limit=256M`
  - [ ] Increase `max_execution_time` for AI generations

### ✅ Monitoring
- [ ] Set up log rotation for `storage/logs/`
- [ ] Configure monitoring/alerting (e.g., UptimeRobot, Sentry)
- [ ] Set up daily metrics summarization cron
- [ ] Enable error reporting to admin email

### ✅ External Services
- [ ] Test Gemini API connectivity and quota
- [ ] Set up Stripe webhook endpoint:
  - URL: `https://tutorly.space/api/webhooks/stripe`
  - Events: `checkout.session.completed`, `invoice.payment_succeeded`, `customer.subscription.deleted`
- [ ] Verify Redis connection and persistence
- [ ] Test email service (when implemented)

### ✅ Testing
Run the following tests before deploying:

#### 1. Health Check
```bash
curl https://tutorly.space/api/health
# Expected: {"ok":true,"ts":"2025-10-29T..."}
```

#### 2. CORS Headers
```bash
curl -I -X OPTIONS https://tutorly.space/api/auth/login \
  -H "Origin: https://tutorly.space"
# Expected: Access-Control-Allow-Origin: https://tutorly.space
```

#### 3. Authentication Flow
```bash
# Signup
curl -X POST https://tutorly.space/api/auth/signup \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Test User","email":"test@example.com","password":"Test123456"}'

# Login
curl -X POST https://tutorly.space/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"Test123456"}'
```

#### 4. Rate Limiting
```bash
# Make 6 login attempts quickly
for i in {1..6}; do
  curl -X POST https://tutorly.space/api/auth/login \
    -H "Content-Type: application/json" \
    -d '{"email":"test@example.com","password":"wrong"}'
  echo ""
done
# Expected: 5 × 401 Unauthorized, 1 × 429 Too Many Requests
```

#### 5. Lesson Generation
```bash
TOKEN="your-jwt-token"

curl -X POST https://tutorly.space/api/lessons/generate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"topic":"Test","language":"English"}'
# Expected: 201 Created with lesson object
```

#### 6. Payment Required (Free Trial Exhausted)
```bash
# Make second generation attempt
curl -X POST https://tutorly.space/api/lessons/generate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"topic":"Test 2","language":"English"}'
# Expected: 402 Payment Required with upgrade details
```

#### 7. Admin Stats
```bash
curl https://tutorly.space/api/admin/stats \
  -H "Authorization: Bearer $TOKEN"
# Expected: 200 OK with statistics object
```

### ✅ Backup & Recovery
- [ ] Create full database backup before deployment
- [ ] Document rollback procedure
- [ ] Keep previous version in `/var/www/tutorly-api.backup`

### ✅ Documentation
- [ ] Review `RELEASE_NOTES.md`
- [ ] Update `BACKEND_API_CONTRACT.md` if needed
- [ ] Document any production-specific configurations
- [ ] Create runbook for common operations

## Post-Deployment Checklist

### Immediate (First Hour)
- [ ] Monitor error logs: `tail -f storage/logs/app.log`
- [ ] Check metrics logs: `tail -f storage/logs/metrics.log`
- [ ] Test critical user flows (signup → generation → payment)
- [ ] Verify Stripe webhook is receiving events
- [ ] Monitor Redis memory usage: `redis-cli INFO memory`

### First 24 Hours
- [ ] Review all 4xx/5xx errors in metrics log
- [ ] Check database query performance
- [ ] Monitor API response times
- [ ] Verify rate limiting is working correctly
- [ ] Check Gemini API usage and costs

### First Week
- [ ] Review admin stats daily
- [ ] Monitor user feedback and support tickets
- [ ] Analyze most common error patterns
- [ ] Optimize slow queries if needed
- [ ] Review and adjust rate limits if necessary

## Emergency Contacts

- **Server Admin**: [Contact Info]
- **Database Admin**: [Contact Info]
- **Stripe Support**: https://support.stripe.com
- **Google Cloud Support**: (for Gemini API issues)

## Rollback Procedure

If critical issues arise:

1. **Immediate Rollback**
   ```bash
   cd /var/www
   mv tutorly-api tutorly-api.failed
   mv tutorly-api.backup tutorly-api
   sudo systemctl restart apache2
   ```

2. **Database Rollback** (if migrations were run)
   ```bash
   mysql -u root -p tutorly < backup-YYYY-MM-DD.sql
   ```

3. **Clear Redis** (if needed)
   ```bash
   redis-cli FLUSHALL
   ```

4. **Notify Users**
   - Post status update
   - Send notification email (if implemented)

## Production Environment Details

- **Server**: Ubuntu 24.04 LTS
- **PHP Version**: 8.1+
- **Database**: MySQL 8.0 / MariaDB 10.6+
- **Redis Version**: 7.0+
- **Web Server**: Apache 2.4
- **SSL/TLS**: Let's Encrypt (Certbot)

## Maintenance Windows

- **Weekly**: Sunday 2:00 AM - 4:00 AM UTC
- **Emergency**: As needed with 30-minute notice

## Status Page

Consider setting up a status page at `status.tutorly.space` to communicate:
- System uptime
- Planned maintenance
- Incident reports

---

**Last Updated**: 2025-10-29
**Version**: 1.0.0
**Maintained By**: [Team Name]

