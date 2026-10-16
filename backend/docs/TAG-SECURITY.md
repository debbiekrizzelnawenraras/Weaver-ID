# Tag security layer

Secured NTAG215 tags have a write password and a read counter. The password stops the tag from being edited. The counter lets the server notice a copy or a replayed scan.

## Setup (every developer, after pulling)

1. Make your own key: `php -r "echo bin2hex(random_bytes(32));"`
2. Put it in `backend/.env` as `WEAVER_TAG_KEY=<the key>`. Never commit `.env` or share the key in chat or on GitHub.
3. Back the key up outside your laptop. Without it, a secured tag can never be rewritten (reads and verification still work).
4. Run `php artisan migrate`.
5. Tags secured with one key can only be re-secured or rewritten with the same key. Tags from another developer's database need their key.

Optional: `WEAVER_COUNTER_WINDOW` (default 1000), how far the counter may jump above the saved one.

## Securing a tag

1. Start the server and the reader bridge: `scripts\start-weaver-id.bat` (add `phone` to let a phone on the same Wi-Fi reach it).
2. In the dashboard, **Link a tag**, put the tag on the reader and keep it there. It is secured automatically. A **Secure** button on the tag's row does it later.
3. Write the product info before securing. A secured tag cannot be written without the password.
4. **Re-secure** resets the saved counter. Use it if a genuine tag is flagged for a counter problem.

## What the server flags

| Reason in the scan log | Meaning |
|---|---|
| `counter_missing` | A secured tag arrived without a counter (typed UID, old app, or a phone that cannot read it) |
| `counter_rollback` | The counter is lower than the saved one: a copy, or a replay |
| `counter_jump` | The counter is more than the window above the saved one. Not saved, so it cannot lock the real tag out |
| `many_devices` | Too many different devices scanned the tag in the window |

## Limits to know about

- The counter comes from the phone and is not signed. Someone who forges the app's requests can send a plausible number. This layer catches copied and replayed tags, not a determined attacker. Tags that sign their reads (NTAG 424 DNA) are a different product.
- Reading the counter needs an Android phone with NFC that allows raw reads. On an iPhone, or an Android phone that refuses, a genuine secured tag is flagged `counter_missing`.
- A genuine tag tapped more than the window's worth of times by phones that never reached the server will be flagged `counter_jump` until it is re-secured.
- `scripts\reset-demo.bat` wipes the database, including secured states and saved counters. Tags must be secured again afterwards.
