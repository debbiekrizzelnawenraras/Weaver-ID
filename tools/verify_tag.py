"""
Weaver-ID PC check: scan a tag on the ACR122U the way the phone app does.

The app reads four things from a tag in one tap: its UID, its read counter and
the tag code (pages 4..11), then sends them to POST /api/verify. This script does
exactly that from the laptop, so you can test the server and the tag without a phone.
It proves the tag holds the right code and that the server accepts it. It does NOT
test the phone's own NFC reading (the Flutter code), which still needs a real phone.

Usage (Windows, no packages needed, ACR122U plugged in, Laravel server running):
    python verify_tag.py                     read one tag and verify it
    python verify_tag.py --dry-run           read the tag and print it, send nothing
    python verify_tag.py --no-code           send no tag code (what the OLD app does)
    python verify_tag.py --server http://127.0.0.1:8000

Forgery tests. The script still reads the real tag, then lies about one thing, the way a
forger would. Each is clearly labelled FORGED in the output. The tag itself is never changed:
    python verify_tag.py --code 0000000000000000   send a wrong tag code (16 hex characters)
    python verify_tag.py --counter 5000            send this counter instead of the tag's own
    python verify_tag.py --device phone-2          send as another device (many-devices test)

Every real run is a genuine scan: the tag's counter goes up by one and the server
writes a scan log. Use --dry-run to avoid the server log (the tag still counts the tap).
"""
import json
import os
import sys
import time
import urllib.error
import urllib.request

DEFAULT_SERVER = "http://127.0.0.1:8000"
DEVICE_ID = "pc-reader-test"


def read_like_the_app(link):
    """Same commands, same order as _scanTag in mobile/lib/main.dart.

    Returns (uid, counter, code). counter and code are None when the tag does not
    give them, exactly as the app treats a failed read.
    """
    import reader_bridge as rb

    uid = link.uid().upper()
    counter = None
    try:
        link.thru(b"\x30\x04")                       # a memory read makes the tag count this tap
        raw = bytes(link.thru(b"\x39\x02"))          # READ_CNT
        if len(raw) == 3:
            counter = int.from_bytes(raw, "little")
    except Exception:
        counter = None
    code = None
    try:
        first = bytes(link.thru(b"\x30\x04"))        # pages 4..7
        second = bytes(link.thru(b"\x30\x08"))       # pages 8..11
        code = rb.parse_code_record(first + second)
    except Exception:
        code = None
    return uid, counter, code


def build_request(uid, counter, code, device=DEVICE_ID):
    """The JSON body the app sends. Fields the tag did not give are left out."""
    body = {"uid": uid, "device_id": device}
    if counter is not None:
        body["counter"] = counter
    if code is not None:
        body["code"] = code
    return body


def post_verify(server, body, timeout=10):
    """Returns (http_status, parsed_json_or_None)."""
    req = urllib.request.Request(
        server.rstrip("/") + "/api/verify",
        data=json.dumps(body).encode(),
        headers={"Accept": "application/json", "Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, json.loads(resp.read() or b"{}")
    except urllib.error.HTTPError as err:
        try:
            return err.code, json.loads(err.read() or b"{}")
        except ValueError:
            return err.code, None


def describe(status, data):
    if data is None:
        return "The server answered HTTP %d with something that is not JSON." % status
    if status != 200:
        return "The server refused the request (HTTP %d): %s" % (status, data.get("message") or data)
    lines = ["Result:  %s" % str(data.get("result", "?")).upper(), "Message: %s" % data.get("message", "")]
    product = data.get("product")
    if product:
        lines.append("Product: %s (%s)" % (product.get("title"), product.get("category")))
    assoc = data.get("association")
    if assoc:
        lines.append("Made by: %s, %s" % (assoc.get("name"), assoc.get("municipality")))
    return "\n".join(lines)


def _connect(reader, timeout):
    import check_tag_signature as cts

    return cts._connect(reader, timeout)


FLAGS = ("--dry-run", "--no-code", "--server", "--code", "--counter", "--device")
MAX_COUNTER = 16777215          # 24-bit NFC counter, same limit as the server
USAGE = """Usage:
    python verify_tag.py                     read one tag and verify it
    python verify_tag.py --dry-run           read the tag and print it, send nothing
    python verify_tag.py --no-code           send no tag code (what the OLD app does)
    python verify_tag.py --server ADDRESS    default http://127.0.0.1:8000
  Forgery tests (the tag is only read, never changed):
    python verify_tag.py --code HEX16        send this tag code (16 hex characters)
    python verify_tag.py --counter NUMBER    send this counter (0 to 16777215)
    python verify_tag.py --device NAME       send as this device name"""


def _is_hex16(text):
    return len(text) == 16 and all(c in "0123456789abcdefABCDEF" for c in text)


def parse_args(argv):
    """Returns (options, error). Anything not understood is an error, never ignored,
    so a typo such as `--no code` cannot quietly run the wrong test."""
    opts = {"dry_run": False, "no_code": False, "server": DEFAULT_SERVER,
            "code": None, "counter": None, "device": None}
    i = 0
    while i < len(argv):
        arg = argv[i]
        if arg == "--dry-run":
            opts["dry_run"] = True
        elif arg == "--no-code":
            opts["no_code"] = True
        elif arg == "--server":
            if i + 1 >= len(argv) or argv[i + 1].startswith("-"):
                return None, "--server needs a web address, for example http://127.0.0.1:8000"
            i += 1
            opts["server"] = argv[i]
        elif arg == "--code":
            if i + 1 >= len(argv) or not _is_hex16(argv[i + 1]):
                return None, "--code needs exactly 16 hex characters, for example 0000000000000000"
            i += 1
            opts["code"] = argv[i].upper()
        elif arg == "--counter":
            if i + 1 >= len(argv) or not argv[i + 1].isdigit() or int(argv[i + 1]) > MAX_COUNTER:
                return None, "--counter needs a whole number from 0 to %d" % MAX_COUNTER
            i += 1
            opts["counter"] = int(argv[i])
        elif arg == "--device":
            if i + 1 >= len(argv) or argv[i + 1].startswith("-") or not 0 < len(argv[i + 1]) <= 100:
                return None, "--device needs a name of 1 to 100 characters, for example phone-2"
            i += 1
            opts["device"] = argv[i]
        else:
            import difflib

            near = difflib.get_close_matches(arg, FLAGS, n=1, cutoff=0.6)
            hint = " Did you mean %s?" % near[0] if near else ""
            return None, "I don't understand '%s'.%s" % (arg, hint)
        i += 1
    if opts["no_code"] and opts["code"] is not None:
        return None, "--no-code and --code cannot be used together."
    return opts, None


def main(argv):
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import reader_bridge as rb

    opts, error = parse_args(argv)
    if error:
        print(error)
        print(USAGE)
        return 2
    server = opts["server"]

    reader = rb.Reader()
    if not reader.ok:
        print("Reader problem:", reader.error)
        return 2
    print("Reader:", reader.name)
    print("Place the tag on the reader and keep it there ...")

    card, proto = _connect(reader, 20)
    if card is None:
        print("No tag was placed on the reader.")
        return 2
    try:
        uid, counter, code = read_like_the_app(rb.PcscLink(reader, card, proto))
    except IOError as err:
        print("Could not read the tag:", err)
        return 2
    finally:
        reader.w.SCardDisconnect(card, 0)

    print("UID:     ", uid)
    print("Counter: ", counter if counter is not None else "(none)")
    print("Tag code:", code if code is not None else "(none on the tag)")
    if opts["no_code"]:
        code = None
        print("Sending:  WITHOUT the tag code (--no-code)")
    if opts["code"] is not None:
        code = opts["code"]
        print("FORGED:   sending tag code %s instead of the tag's own (--code)" % code)
    if opts["counter"] is not None:
        counter = opts["counter"]
        print("FORGED:   sending counter %d instead of the tag's own (--counter)" % counter)
    device = opts["device"] or DEVICE_ID
    if opts["device"]:
        print("Device:   sending as '%s' (--device)" % device)
    if opts["dry_run"]:
        print("Dry run: nothing was sent to the server.")
        return 0

    try:
        status, data = post_verify(server, build_request(uid, counter, code, device))
    except (urllib.error.URLError, OSError) as err:
        print("Could not reach the server at %s (%s). Is `php artisan serve` running?" % (server, err))
        return 2
    print(describe(status, data))
    return 0 if status == 200 and data and data.get("result") == "authenticated" else 1


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print("\nBye.")
