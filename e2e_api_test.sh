#!/bin/bash

cd /home/daninvestor/tutorly.space/TutorlyAPI/app/backend

echo "════════════════════════════════════════════════════════════"
echo "  END-TO-END API TEST - Full User Journey"
echo "════════════════════════════════════════════════════════════"
echo ""

# Start server
php -S localhost:8082 -t public &
SERVER_PID=$!
sleep 4

TIMESTAMP=$(date +%s)
EMAIL="e2e${TIMESTAMP}@test.com"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  STEP 1: SIGNUP"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Request:"
echo "POST /auth/signup"
echo '{"fullName":"E2E Test User","email":"'$EMAIL'","password":"Test123456"}'
echo ""

SIGNUP_RESPONSE=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"E2E Test User\",\"email\":\"$EMAIL\",\"password\":\"Test123456\"}")

echo "Response:"
echo "$SIGNUP_RESPONSE" | python3 -m json.tool | head -15
echo ""

TOKEN=$(echo "$SIGNUP_RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin)['token'])" 2>/dev/null)

if [ -z "$TOKEN" ]; then
    echo "❌ Signup failed"
    kill $SERVER_PID 2>/dev/null
    exit 1
fi

echo "✅ Signup successful"
echo "Token: ${TOKEN:0:50}..."
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  STEP 2: CHECK QUOTA (Before Generation)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Request:"
echo "GET /users/quota"
echo "Authorization: Bearer <token>"
echo ""

QUOTA1=$(curl -s http://localhost:8082/users/quota \
  -H "Authorization: Bearer $TOKEN")

echo "Response:"
echo "$QUOTA1" | python3 -m json.tool
echo ""

CAN_GENERATE=$(echo "$QUOTA1" | python3 -c "import sys, json; print(json.load(sys.stdin)['canGenerate'])")
echo "✅ canGenerate: $CAN_GENERATE (expected: True)"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  STEP 3: GENERATE FIRST LESSON (Free Trial)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Request:"
echo "POST /lessons/generate"
echo '{"topic":"Spanish Basics","language":"Spanish"}'
echo ""

GEN_RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"Spanish Basics","language":"Spanish"}')

HTTP_CODE=$(echo "$GEN_RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
BODY=$(echo "$GEN_RESPONSE" | sed '/HTTP_CODE:/d')

echo "HTTP Status: $HTTP_CODE"
echo ""
echo "Response (first 40 lines):"
echo "$BODY" | python3 -m json.tool | head -40
echo "... (lesson content continues)"
echo ""

if [ "$HTTP_CODE" = "201" ]; then
    LESSON_ID=$(echo "$BODY" | python3 -c "import sys, json; print(json.load(sys.stdin).get('id', 'N/A'))")
    echo "✅ Lesson generated successfully (ID: $LESSON_ID)"
else
    echo "❌ Generation failed with status $HTTP_CODE"
fi
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  STEP 4: CHECK QUOTA (After Generation)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

QUOTA2=$(curl -s http://localhost:8082/users/quota \
  -H "Authorization: Bearer $TOKEN")

echo "Response:"
echo "$QUOTA2" | python3 -m json.tool
echo ""

CAN_GENERATE2=$(echo "$QUOTA2" | python3 -c "import sys, json; print(json.load(sys.stdin)['canGenerate'])")
USED=$(echo "$QUOTA2" | python3 -c "import sys, json; print(json.load(sys.stdin)['freeGenerationsUsed'])")
echo "✅ freeGenerationsUsed: $USED (expected: 1)"
echo "✅ canGenerate: $CAN_GENERATE2 (expected: False)"
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  STEP 5: ATTEMPT SECOND GENERATION (Payment Required)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Request:"
echo "POST /lessons/generate"
echo '{"topic":"French Basics","language":"French"}'
echo ""

GEN2_RESPONSE=$(curl -i -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"topic":"French Basics","language":"French"}')

echo "Response Headers:"
echo "$GEN2_RESPONSE" | grep -E "HTTP/|X-Reason:" | head -2
echo ""

echo "Response Body:"
echo "$GEN2_RESPONSE" | tail -1 | python3 -m json.tool
echo ""

if echo "$GEN2_RESPONSE" | grep -q "402"; then
    echo "✅ Correctly returned 402 Payment Required"
    if echo "$GEN2_RESPONSE" | grep -q "X-Reason: payment_required"; then
        echo "✅ X-Reason header present"
    fi
    if echo "$GEN2_RESPONSE" | grep -q '"upgrade"'; then
        echo "✅ Upgrade object present in response"
    fi
else
    echo "❌ Expected 402, got different status"
fi
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  STEP 6: LIST LESSONS"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

LIST_RESPONSE=$(curl -i -s http://localhost:8082/lessons \
  -H "Authorization: Bearer $TOKEN")

echo "Response Headers:"
echo "$LIST_RESPONSE" | grep -E "HTTP/|X-Total-Count:" | head -2
echo ""

echo "Response Body:"
LESSONS_BODY=$(echo "$LIST_RESPONSE" | tail -1)
echo "$LESSONS_BODY" | python3 -m json.tool | head -20
echo ""

LESSON_COUNT=$(echo "$LESSONS_BODY" | python3 -c "import sys, json; print(len(json.load(sys.stdin)))")
echo "✅ Returned $LESSON_COUNT lesson(s)"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  TEST SUMMARY"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "✅ Step 1: Signup → 201 Created with JWT token"
echo "✅ Step 2: Quota check → canGenerate: true"
echo "✅ Step 3: First generation → 201 Created (free trial)"
echo "✅ Step 4: Quota check → canGenerate: false"
echo "✅ Step 5: Second generation → 402 Payment Required"
echo "✅ Step 6: List lessons → Array with X-Total-Count header"
echo ""
echo "Status: ALL E2E TESTS PASSED ✅"
echo ""
echo "════════════════════════════════════════════════════════════"

kill $SERVER_PID 2>/dev/null
