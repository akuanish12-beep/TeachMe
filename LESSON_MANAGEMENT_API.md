# Lesson Management API

## Quick Reference

### Update Lesson (Favorite/Notes)
```bash
curl -X PATCH http://localhost:8082/lessons/{id} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"is_favorite": true, "user_notes": "My notes"}'
```

**Response:**
```json
{
  "message": "Lesson updated successfully",
  "lesson": {
    "id": 19,
    "title": "Lesson Title",
    "topic": "Topic",
    "language": "English",
    "is_favorite": true,
    "user_notes": "My notes",
    "created_at": "2025-10-29 18:24:51"
  }
}
```

### List Favorite Lessons
```bash
curl http://localhost:8082/lessons/favorites \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
[
  {
    "id": 19,
    "title": "Lesson Title",
    "topic": "Topic",
    "language": "English",
    "user_notes": "My notes",
    "created_at": "2025-10-29 18:24:51"
  }
]
```

### Delete Lesson
```bash
curl -X DELETE http://localhost:8082/lessons/{id} \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**
```json
{
  "message": "Lesson deleted successfully",
  "id": 19
}
```

## Database Schema

```sql
ALTER TABLE lessons ADD COLUMN is_favorite TINYINT(1) DEFAULT 0;
ALTER TABLE lessons ADD COLUMN user_notes TEXT;
```

## Security

- All endpoints require JWT authentication
- Ownership is verified before any operation
- 404 returned if lesson not found or not owned by user
- DELETE uses database transactions for safety

## Error Codes

- **401**: Unauthorized (missing or invalid token)
- **404**: Lesson not found or not owned by user
- **422**: Invalid input (PATCH with no valid fields)
- **500**: Server error (database failure)
