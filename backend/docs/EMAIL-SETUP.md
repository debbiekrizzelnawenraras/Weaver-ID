# Email setup (6-digit codes)

Weaver-ID emails only 6-digit codes: to confirm an association's email address when it applies, and for "Forgot password". Nothing else is emailed.

The password of the mail account goes **only** into `backend/.env` on the laptop. `.env` is not committed to Git. Never put it in `.env.example`, a chat, a message or a screenshot.

## 1. Use a dedicated mail account

Use an account made for this project, not a personal one. For Gmail:

1. Turn on 2-step verification for the account.
2. Open the account's "App passwords" page and make one for "Mail". Google shows 16 characters once, usually in four groups with spaces. This is `MAIL_PASSWORD`; type it without the spaces. Your normal Gmail password does not work here.

## 2. Edit `backend/.env`

Replace the `MAIL_` lines with these (your own address instead of the example):

```
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your.project.account@gmail.com
MAIL_PASSWORD=the-16-characters-from-google
MAIL_FROM_ADDRESS=your.project.account@gmail.com
MAIL_FROM_NAME="Weaver-ID"
WEAVER_EMAIL_CODES=on
```

If the server was running, close its window and start it again, because `.env` is read when it starts.

## 3. Test it

1. Open `http://127.0.0.1:8000/register.html`, type a real address you can read, press **Send code**. The email should arrive within a minute (look in spam too).
2. Open `http://127.0.0.1:8000/forgot-password.html` and ask for a code for an account whose email you can read.

If the page says "The code could not be sent", the reason is the last line of `backend/storage/logs/laravel.log` (wrong app password, no internet). The log never contains the code.

## Without internet (rehearsal)

Set `WEAVER_EMAIL_CODES=off` and restart the server. The application page then has no code box, applications are saved as "Email not confirmed" (the Applications tab shows it), and forgot-password says it is switched off. Set it back to `on` when internet is available.

`MAIL_MAILER=log` writes each email, code included, into `storage/logs/laravel.log` instead of sending it. That is for developers only: nobody receives anything.
