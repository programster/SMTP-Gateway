# The management API

Everything you can do with email tokens, rate limits and providers in the web interface can be done
through `/api/v1`, with a key from **API keys** as `Authorization: Bearer <API key>`. An API key is one
string like an email token, starting `ak_` instead, so the service can tell you if you've used one where
the other goes. The OpenAPI document is
published at [`/openapi.json`](/openapi.json), and can be read at `/docs` (Redoc) or tried at
`/docs/swagger` (Swagger UI).

Errors are returned with a suitable HTTP status, and a body of
`{"error": {"message": "...", "code": 1}}`, where `code` is from `ErrorCodeEnum`.
