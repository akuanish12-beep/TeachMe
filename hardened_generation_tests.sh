#!/bin/bash

cd /home/daninvestor/tutorly.space/TutorlyAPI/app/backend

echo "════════════════════════════════════════════════════════════"
echo "  HARDENED GENERATION PIPELINE TESTS"
echo "════════════════════════════════════════════════════════════"
echo ""

# Test 1: English Lesson
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 1: English Lesson Generation"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
TIMESTAMP=$(date +%s)
EMAIL1="english-${TIMESTAMP}@test.com"
echo "Creating user: $EMAIL1"
SIGNUP1=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"English Test\",\"email\":\"$EMAIL1\",\"password\":\"Test123\"}")
TOKEN1=$(echo "$SIGNUP1" | jq -r '.token')
echo "Token: ${TOKEN1:0:40}..."
echo ""
echo "Generating English lesson..."
GEN1=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN1" \
  -d '{"topic":"Basic Greetings","language":"English"}')
echo "$GEN1" | jq '{id:.id, title:.lesson.title, language:.lesson.language, sections:(.lesson.sections|length), exercises:{fill:(.lesson.exercises.fill_in_the_blanks|length),translate:(.lesson.exercises.translate_phrase|length),answer:(.lesson.exercises.answer_question|length)}}'
echo ""

# Test 2: Spanish Lesson
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 2: Spanish Lesson Generation"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
sleep 2
EMAIL2="spanish-$(date +%s)@test.com"
echo "Creating user: $EMAIL2"
SIGNUP2=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"Spanish Test\",\"email\":\"$EMAIL2\",\"password\":\"Test123\"}")
TOKEN2=$(echo "$SIGNUP2" | jq -r '.token')
echo "Token: ${TOKEN2:0:40}..."
echo ""
echo "Generating Spanish lesson..."
GEN2=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN2" \
  -d '{"topic":"Números y colores","language":"Spanish"}')
echo "$GEN2" | jq '{id:.id, title:.lesson.title, language:.lesson.language, sections:(.lesson.sections|length), exercises:{fill:(.lesson.exercises.fill_in_the_blanks|length),translate:(.lesson.exercises.translate_phrase|length),answer:(.lesson.exercises.answer_question|length)}}'
echo ""

# Test 3: French Lesson
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  TEST 3: French Lesson Generation"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
sleep 2
EMAIL3="french-$(date +%s)@test.com"
echo "Creating user: $EMAIL3"
SIGNUP3=$(curl -s -X POST http://localhost:8082/auth/signup \
  -H "Content-Type: application/json" \
  -d "{\"fullName\":\"French Test\",\"email\":\"$EMAIL3\",\"password\":\"Test123\"}")
TOKEN3=$(echo "$SIGNUP3" | jq -r '.token')
echo "Token: ${TOKEN3:0:40}..."
echo ""
echo "Generating French lesson..."
GEN3=$(curl -s -X POST http://localhost:8082/lessons/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN3" \
  -d '{"topic":"Les verbes quotidiens","language":"French"}')
echo "$GEN3" | jq '{id:.id, title:.lesson.title, language:.lesson.language, sections:(.lesson.sections|length), exercises:{fill:(.lesson.exercises.fill_in_the_blanks|length),translate:(.lesson.exercises.translate_phrase|length),answer:(.lesson.exercises.answer_question|length)}}'
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  Check logs for retry behavior..."
echo "════════════════════════════════════════════════════════════"
tail -20 storage/logs/app.log | grep -E "GeminiService|attempt|retry|Successfully|warning"
echo ""
echo "Tests complete!"

