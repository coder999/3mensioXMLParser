# 3mensioxmlparser

## Dev/deploy workflow (nexus -> VPS)

- Work directly on `main` in the served working copy at `/home/mark/docker/html-local/3mensioxmlparser` — nexus
  serves it live, so changes show up on refresh. Don't develop in
  worktrees or temp checkouts; the web server doesn't serve them. Use a
  feature branch only for a large/risky change that may sit unfinished.
- Pushing `main` does NOT deploy. Production deploys are manual only:
  Actions tab -> Run workflow, or `gh workflow run deploy.yml`.
