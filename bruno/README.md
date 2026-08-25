# Bruno API collection

Open `bruno/` as a collection and select the `local` environment.

Seed the local Laravel database before logging in:

```bash
docker compose exec -T api php artisan db:seed
```

The login request stores its bearer token as a Bruno runtime variable. The
create-session request likewise stores the returned session ID and one-time
write token for the following request.

Before creating a learning session, replace `respondentCode` in
`environments/local.bru` with the authenticated student's existing research
participant code. Participant creation is intentionally not exposed by the
current API.
