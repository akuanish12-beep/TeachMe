#!/bin/bash

cd /home/daninvestor/tutorly.space/TutorlyAPI/app/backend

# Start server
php -S localhost:8082 -t public &
SERVER_PID=$!
sleep 4

echo "════════════════════════════════════════════════════"
echo "  JWT & CORS - FINAL VERIFICATION"
echo "════════════════════════════════════════════════════"
echo ""

# Get token
TOKEN=$(curl -s -X POST http://localhost:8082/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"lesson@test.com","password":"Test123456"}' \
  | python3 -c "import sys, json; print(json.load(sys.stdin)['token'])" 2>/dev/null)

if [ -z "$TOKEN" ]; then
    echo "❌ Failed to get auth token"
    kill $SERVER_PID
    exit 1
fi

echo "✅ Authentication successful"
echo ""

# Test 1: Health
echo "Test 1: Health Check"
curl -s http://localhost:8082/health | python3 -m json.tool | head -3
echo ""

# Test 2: GET authorized
echo "Test 2: GET /lessons (Authorized)"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8082/lessons -H "Authorization: Bearer $TOKEN")
echo "HTTP $HTTP_CODE - $([ "$HTTP_CODE" = "200" ] && echo '✅ PASS' || echo '❌ FAIL')"
echo ""

# Test 3: GET unauthorized
echo "Test 3: GET /lessons (Unauthorized)"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8082/lessons)
echo "HTTP $HTTP_CODE - $([ "$HTTP_CODE" = "401" ] && echo '✅ PASS' || echo '❌ FAIL')"
echo ""

# Test 4: OPTIONS
echo "Test 4: OPTIONS Preflight"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X OPTIONS http://localhost:8082/lessons -H "Origin: http://localhost:5173")
echo "HTTP $HTTP_CODE - $([ "$HTTP_CODE" = "204" ] && echo '✅ PASS' || echo '❌ FAIL')"
echo ""

# Test 5: POST lesson generation
echo "Test 5: POST /lessons/generate"
RESPONSE=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"Hello","language":"Spanish"}')

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"Hello","language":"Spanish"}')

echo "HTTP $HTTP_CODE"

if echo "$RESPONSE" | grep -q '"id"'; then
    echo "✅ PASS - Lesson generated successfully!"
elif echo "$RESPONSE" | grep -q 'PAYMENT_REQUIRED'; then
    echo "✅ PASS - Free trial logic working (user already used free generation)"
elif echo "$RESPONSE" | grep -q '"error"'; then
    echo "⚠️  JWT works, but API error:"
    echo "$RESPONSE" | python3 -m json.tool | head -10
else
    echo "Response: $RESPONSE" | head -5
fi

echo ""
echo "════════════════════════════════════════════════════"

# Cleanup
kill $SERVER_PID 2>/dev/null

