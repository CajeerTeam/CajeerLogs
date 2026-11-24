# ChatGPT Assistant — 1.0.6

The assistant is disabled by default. It makes a real HTTPS POST to `https://api.openai.com/v1/responses`. PHP cURL, working DNS/TLS and an OpenAI API project/key with access to the selected model are required. ChatGPT subscriptions do not supply API credits. Native install: set the following in the private `.env`; Docker: set them in the production Compose environment and recreate app services. Do not expose the key in browser code or commit it.

```dotenv
AI_ASSISTANT_ENABLED=true
OPENAI_API_KEY=your-project-key
OPENAI_MODEL=gpt-6.1-sol
AI_ASSISTANT_INCLUDE_MESSAGES=false
```

Use a supported Responses model available to your API project. Native installs can use `OPENAI_API_KEY_FILE` instead of the key (absolute private file). Set a provider project budget separately. Enable `AI_ASSISTANT_INCLUDE_MESSAGES=true` only when authorized to transfer log text externally. Review privacy obligations and OpenAI API data retention before enabling; `store:false` is not a guarantee of zero retention. Redaction is best-effort, not a DLP guarantee. Arbitrary secrets and personal information may still appear in free text; do not enter them. Context JSON, hosts, trace IDs, tags and credentials are not selected for transmission. The metadata-only default sends event IDs, severity and timestamps. Questions and short chat history are also transmitted after masking known patterns.

In the admin panel choose AI Assistant and a project, time interval (1–168 hours), severity and optional keywords. Type a question and consent to the external transfer. Up to 20 most recent matching events are retrieved using the normal PostgreSQL search predicate and budget; results can be truncated. Enable message text if approved to obtain deeper answers. The assistant cannot execute commands, queries or tools. Verify its claims against the listed event IDs; the sample is not an exhaustive incident report. Browser memory holds the last three turns for follow-ups; navigating away, switching project or signing out discards the chat. No chat history is stored on the server. Each request requires explicit consent. Existing session, CSRF, 2FA and project `logs:read` restrictions apply; API tokens cannot call the assistant. Current grants are rechecked before disclosure and before returning a response.

Limits: 6 calls/user/minute, 30 global/minute, 60/user/hour and 300 global/hour, plus normal shared API/search quotas. Output is capped at 1,200 tokens, timeout 40 seconds, no automatic retries. An interrupted request may still incur provider charges. Audit records counts and usage, not prompts, responses or credentials. Disable the feature and restart services to stop requests. No database migration is required.

Test: `php tests/assistant.php`; `php bin/cajeer openapi:lint`. Live acceptance requires a configured API key and a real PostgreSQL project: enable, sign in, ask about known events with a keyword, verify listed event IDs, test another project without grants and revoked sessions, then disable. Never use live production secrets as test data.
