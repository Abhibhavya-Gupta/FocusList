# Dayline

Dayline is a private, personal to-do list that turns plain-English notes into organized tasks. Each account sees only its own tasks. It includes due dates, priorities, categories, task breakdowns, daily planning, and next-step suggestions.

## Technology

- Frontend: HTML, CSS, JavaScript, jQuery
- Backend: PHP 8.3, JSON API, bearer-token authentication
- Database: MySQL 8
- AI: OpenAI and Gemini, with local rule-based behavior when no key is configured or a provider is unavailable

## Run locally

1. Install Docker Desktop and start it.
2. From the project folder, run:

   ```sh
   docker compose up --build
   ```

3. Open [http://localhost:8080](http://localhost:8080), create an account, and try a task such as `Call dentist tomorrow 4pm high`.

If the default ports are already in use, set `APP_PORT` and/or `MYSQL_PORT` before starting Compose.

To use a hosted AI provider locally, copy `.env.example` to `.env` and add either or both API keys:

```dotenv
OPENAI_API_KEY=
GEMINI_API_KEY=
AI_PROVIDER=auto
```

`AI_PROVIDER` can be `auto`, `openai`, `gemini`, or `local`. In `auto` mode, OpenAI is tried first and Gemini is used if it is configured and OpenAI is unavailable. Without usable keys, local rules continue to handle task parsing, planning, and suggestions.

## Deploy to Railway

Push the project to a GitHub repository first. Railway can then host the complete app (PHP/Apache, frontend, and API) and its MySQL database:

1. Create a Railway project from this repository and add a MySQL service.
2. Deploy the repository using the included `Dockerfile` / `railway.toml`.
3. Set these variables on the web service:
   - `JWT_SECRET`: a randomly generated secret with at least 32 characters.
   - `FRONTEND_ORIGIN`: the public Railway app URL. For a separate Vercel frontend, add its exact HTTPS origin as well (comma-separated).
   - Optionally `OPENAI_API_KEY`, `GEMINI_API_KEY`, and `AI_PROVIDER`.
4. Connect the MySQL service variables (`MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, and `MYSQLPASSWORD`) to the web service if Railway has not shared them automatically.
5. Wait for the `/api/health` deployment health check, then open the Railway app URL and register an account.

The database schema is created by the API on startup. Never use the example/local database credentials or a short/default JWT secret for a public deployment.

## Optional Vercel frontend

The full-stack Railway URL is the simplest deployment. To serve only the static frontend from Vercel instead:

1. Deploy the GitHub repository to Vercel with the project root set to the repository root; `vercel.json` publishes the `frontend` directory.
2. Before deploying, set `window.DAYLINE_API_BASE` in `frontend/js/config.js` to `https://YOUR-RAILWAY-DOMAIN/api`.
3. Add the Vercel site's exact HTTPS origin to Railway's `FRONTEND_ORIGIN` variable, then redeploy the Railway service.

Vercel hosts only the static files; the PHP API and MySQL database remain on Railway.
