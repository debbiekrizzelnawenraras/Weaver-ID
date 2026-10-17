# Tag security layer

Secured NTAG215 tags have a write password, a read counter and a tag code. The password stops the tag from being edited. The counter lets the server notice a copy or a replayed scan. The tag code is a short code the server derives from the UID and `WEAVER_TAG_KEY`; the tag desk writes it into the tag's memory and the phone sends it with the UID, so a typed UID, a UID-only copy, or a code taken from another tag fails.

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
4. **Re-secure** resets the saved counter and writes the tag code. Use it if a genuine tag is flagged for a counter problem, and for tags secured before the tag code existed (they are not checked for a code until re-secured).
5. The code is stored as a standard NDEF record of type `weaver.id:t` in pages 4 to 10, which replaces anything already written there. The tag's capability container (page 3) is never touched.

## What the server flags

| Reason in the scan log | Meaning |
|---|---|
| `counter_missing` | A secured tag arrived without a counter (typed UID, old app, or a phone that cannot read it) |
| `counter_rollback` | The counter is lower than the saved one: a copy, or a replay |
| `counter_jump` | The counter is more than the window above the saved one. Not saved, so it cannot lock the real tag out |
| `code_missing` | A tag secured with a tag code arrived without one (typed UID, a UID-only copy, an old app, or a phone that cannot read pages) |
| `code_invalid` | The code does not match the one the server derives for this UID: a code copied from another tag, or a forged request |
| `many_devices` | Too many different devices scanned the tag in the window |

## Limits to know about

- The counter comes from the phone and is not signed. Someone who forges the app's requests can send a plausible number. This layer catches copied and replayed tags, not a determined attacker. Tags that sign their reads (NTAG 424 DNA) are a different product.
- The tag code can be read by anyone who can read the tag (reads are open so the phone app can read it). A full copy of the tag's memory onto a tag whose UID can be changed carries a valid code. The counter is then the remaining check, and it is unsigned. Only tags that sign each tap (NTAG 424 DNA) close this.
- Reading the counter needs an Android phone with NFC that allows raw reads. On an iPhone, or an Android phone that refuses, a genuine secured tag is flagged `counter_missing`.
- A genuine tag tapped more than the window's worth of times by phones that never reached the server will be flagged `counter_jump` until it is re-secured.
- Reading the code needs the same Android raw NFC access as the counter. Where the phone cannot read it, a tag with a code is flagged `code_missing`.
- `scripts\reset-demo.bat` wipes the database, including secured states and saved counters. Tags must be secured again afterwards.
