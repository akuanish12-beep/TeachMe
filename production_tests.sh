#!/bin/bash

cd /home/daninvestor/tutorly.space/TutorlyAPI/app/backend

echo "════════════════════════════════════════════════════════════"
echo "  PRODUCTION-HARDENED GENERATION TESTS"
echo "════════════════════════════════════════════════════════════"
echo ""

# Test 1: English
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 1: English Lesson (Business Meetings)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
EMAIL1="eng-prod-$(date +%s)@test.com"
echo "Creating user..."
TOKEN1=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"Prod Test 1\",\"email\":\"$EMAIL1\",\"password\":\"Test123456\"}" | jq -r '.token')
echo "✓ Token: ${TOKEN1:0:40}..."
echo "Generating lesson..."
GEN1=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN1" \
  -d '{"topic":"Business meeting phrases","language":"English"}')
echo "$GEN1" | jq '{success:(.id!=null), id:.id, title:.lesson.title, language:.lesson.language, sections:(.lesson.sections|length), exercises:(.lesson.exercises.fill_in_the_blanks|length)+(.lesson.exercises.translate_phrase|length)+(.lesson.exercises.answer_question|length)}'
echo ""

# Test 2: Spanish
sleep 2
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 2: Spanish Lesson (Restaurante)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
EMAIL2="spa-prod-$(date +%s)@test.com"
echo "Creating user..."
TOKEN2=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"Prod Test 2\",\"email\":\"$EMAIL2\",\"password\":\"Test123456\"}" | jq -r '.token')
echo "✓ Token: ${TOKEN2:0:40}..."
echo "Generating lesson..."
GEN2=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN2" \
  -d '{"topic":"Pedir comida en un restaurante","language":"Spanish"}')
echo "$GEN2" | jq '{success:(.id!=null), id:.id, title:.lesson.title, language:.lesson.language, sections:(.lesson.sections|length), exercises:(.lesson.exercises.fill_in_the_blanks|length)+(.lesson.exercises.translate_phrase|length)+(.lesson.exercises.answer_question|length)}'
echo ""

# Test 3: French
sleep 2
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 3: French Lesson (Shopping)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
EMAIL3="fra-prod-$(date +%s)@test.com"
echo "Creating user..."
TOKEN3=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"Prod Test 3\",\"email\":\"$EMAIL3\",\"password\":\"Test123456\"}" | jq -r '.token')
echo "✓ Token: ${TOKEN3:0:40}..."
echo "Generating lesson..."
GEN3=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN3" \
  -d '{"topic":"Faire des achats","language":"French"}')
echo "$GEN3" | jq '{success:(.id!=null), id:.id, title:.lesson.title, language:.lesson.language, sections:(.lesson.sections|length), exercises:(.lesson.exercises.fill_in_the_blanks|length)+(.lesson.exercises.translate_phrase|length)+(.lesson.exercises.answer_question|length)}'
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  PRODUCTION LOGS (Recent Generation Activity)"
echo "════════════════════════════════════════════════════════════"
tail -30 storage/logs/app.log | grep -E "GeminiService initialized|Gemini API attempt|Successfully|warning|error" | tail -15
echo ""
echo "✅ All tests complete!"

