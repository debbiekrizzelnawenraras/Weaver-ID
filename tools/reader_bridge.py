"""
Weaver-ID reader bridge: lets the dashboard read a tag from the ACR122U.

Browsers cannot talk to a PC/SC card reader, so this tiny program runs on the
same laptop and answers the dashboard's "Read from reader" button.

Run (no packages needed, Windows):
    python reader_bridge.py

No reader handy? Pretend one tag is on it, for testing:
    python reader_bridge.py --fake 04AABBCC410289

It listens on 127.0.0.1:8777 only (this laptop, nobody else) and only
accepts requests from pages served from localhost / 127.0.0.1.

Besides reading a tag's UID, the bridge can secure an NTAG215 tag (POST /secure):
it sets the tag's write password and turns on the tag's read counter. It never
sets the permanent lock bits, so a secured tag can still be repaired.
"""
import json
import re
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

PORT = 8777
ORIGIN_OK = re.compile(r"^http://(localhost|127\.0\.0\.1)(:\d+)?$")
LOCK = threading.Lock()  # one read at a time


# ----------------------------------------------------------------------------
# NTAG215 securing
#
# NTAG215 memory map (135 pages of 4 bytes):
#   0x83 CFG0   byte3 = AUTH0 (first page that needs the password, 0xFF = none)
#   0x84 CFG1   byte0 = ACCESS: bit7 PROT (1 = password also needed to READ),
#               bit6 CFGLCK (PERMANENT lock of the configuration: never set),
#               bit4 NFC_CNT_EN (read counter on), bits2..0 AUTHLIM
#   0x85 PWD    4 bytes, always reads back as zeros
#   0x86 PACK   2 bytes (+2 unused), reads back as zeros
# Commands: 0x60 GET_VERSION, 0x30 READ, 0x1B PWD_AUTH, 0x39 0x02 READ_CNT.
# ----------------------------------------------------------------------------
PAGE_CFG0, PAGE_CFG1, PAGE_PWD, PAGE_PACK = 0x83, 0x84, 0x85, 0x86
AUTH0_FROM_USER_MEMORY = 0x04
ACCESS_CNT_EN = 0x10
ACCESS_PROT = 0x80
ACCESS_CFGLCK = 0x40
NTAG215_STORAGE_CODE = 0x11


class SecureError(Exception):
    """A problem the person at the tag desk can understand and fix."""


def secure_ntag215(link, uid_hex, pwd, pack):
    """Set the write password and turn on the read counter. Returns the counter value.

    `link` offers uid(), read16(page), write(page, 4 bytes) and thru(command bytes).
    Safe to run again on a tag it already secured with the same password.
    PROT stays 0, so anybody can still READ the tag (the phone app needs that);
    only WRITING needs the password. CFGLCK is never set.
    """
    if len(pwd) != 4 or len(pack) != 2:
        raise SecureError("Internal error: bad password length.")
    if link.uid().upper() != uid_hex.upper():
        raise SecureError("The tag on the reader is not the tag that was selected. Place the right tag and try again.")

    try:
        version = link.thru(b"\x60")
    except Exception:
        raise SecureError("The tag did not answer. Hold it flat on the reader and try again.")
    if len(version) < 8 or version[1] != 0x04 or version[6] != NTAG215_STORAGE_CODE:
        raise SecureError("This is not an NTAG215 tag, so it was not changed.")

    cfg = link.read16(PAGE_CFG0)          # pages 0x83..0x86
    cfg0, cfg1 = bytes(cfg[0:4]), bytes(cfg[4:8])
    if cfg1[0] & ACCESS_CFGLCK:
        raise SecureError("This tag's configuration is permanently locked. It cannot be secured.")

    if cfg0[3] != 0xFF:
        # Already password protected: fine only if it is our password.
        try:
            reply = link.thru(b"\x1b" + pwd)
        except Exception:
            reply = b""
        if bytes(reply[:2]) != pack:
            raise SecureError("This tag is already protected with another password. It was not changed.")
    else:
        link.write(PAGE_PWD, pwd)
        link.write(PAGE_PACK, pack + b"\x00\x00")
        access = (cfg1[0] & 0x20) | ACCESS_CNT_EN        # PROT=0, CFGLCK=0, AUTHLIM=0
        new_cfg1 = bytes([access]) + cfg1[1:4]
        link.write(PAGE_CFG1, new_cfg1)
        if bytes(link.read16(PAGE_CFG1)[0:4]) != new_cfg1:
            raise SecureError("The tag did not accept the counter setting. Try again with the tag flat on the reader.")
        new_cfg0 = cfg0[0:3] + bytes([AUTH0_FROM_USER_MEMORY])
        link.write(PAGE_CFG0, new_cfg0)                  # last: from here on writes need the password
        if bytes(link.read16(PAGE_CFG0)[0:4]) != new_cfg0:
            raise SecureError("The tag did not accept the password setting. Try again.")
        try:
            reply = link.thru(b"\x1b" + pwd)
        except Exception:
            reply = b""
        if bytes(reply[:2]) != pack:
            raise SecureError("The password check failed after writing. Do not use this tag; try securing it again.")

    link.read16(4)                                        # a memory read makes the tag count this tap
    raw = link.thru(b"\x39\x02")
    if len(raw) < 3:
        raise SecureError("The tag's counter could not be read.")
    return int.from_bytes(bytes(raw[:3]), "little")


class FakeNtag215:
    """Behaves like an NTAG215 for tests and for `--fake` mode (no reader needed)."""

    def __init__(self, uid_hex="04AABBCCDDEE80", counter=0):
        self.uid_hex = uid_hex.upper()
        self.mem = [bytearray(4) for _ in range(135)]
        uid = bytes.fromhex(self.uid_hex)
        self.mem[0][:] = uid[0:3] + bytes([0x00])
        self.mem[1][:] = uid[3:7] if len(uid) >= 7 else bytes(4)
        self.mem[0x83][:] = b"\x00\x00\x00\xff"
        self.mem[0x84][:] = b"\x00\x00\x00\x00"
        self.counter = counter
        self.version = bytes([0x00, 0x04, 0x04, 0x02, 0x01, 0x00, 0x11, 0x03])
        self.new_session()

    def new_session(self):
        """Tag leaves the field and comes back: authentication is forgotten."""
        self.authed = False
        self._counted = False

    # -- helpers
    def _auth0(self):
        return self.mem[0x83][3]

    def _prot(self):
        return bool(self.mem[0x84][0] & ACCESS_PROT)

    def _cnt_en(self):
        return bool(self.mem[0x84][0] & ACCESS_CNT_EN)

    # -- link interface
    def uid(self):
        return self.uid_hex

    def read16(self, page):
        if self._prot() and not self.authed and any(p >= self._auth0() for p in range(page, page + 4)):
            raise IOError("read needs the password")
        if self._cnt_en() and not self._counted:
            self.counter = (self.counter + 1) & 0xFFFFFF
            self._counted = True
        out = bytearray()
        for p in range(page, page + 4):
            q = p % 135
            out += bytes(4) if q in (0x85, 0x86) else bytes(self.mem[q])
        return bytes(out)

    def write(self, page, data):
        if len(data) != 4:
            raise IOError("write needs 4 bytes")
        if page < 4:
            raise IOError("page is locked")
        if page > 0x86:
            raise IOError("no such page")
        if page >= self._auth0() and not self.authed:
            raise IOError("write needs the password")
        if page in (0x83, 0x84) and self.mem[0x84][0] & ACCESS_CFGLCK:
            raise IOError("configuration is locked")
        self.mem[page][:] = data

    def thru(self, cmd):
        cmd = bytes(cmd)
        if cmd[:1] == b"\x60":
            return self.version
        if cmd[:1] == b"\x1b":
            if bytes(self.mem[0x85]) == cmd[1:5]:
                self.authed = True
                return bytes(self.mem[0x86][0:2])
            raise IOError("wrong password")
        if cmd[:2] == b"\x39\x02":
            if not self._cnt_en():
                raise IOError("counter is off")
            return self.counter.to_bytes(3, "little")
        if cmd[:1] == b"\x30":
            return self.read16(cmd[1])
        raise IOError("unknown command")


class Reader:
    """Windows smart card service (winscard.dll) through ctypes."""

    def __init__(self, fake_uid=None):
        self.fake = fake_uid
        self.fake_tag = FakeNtag215(fake_uid) if fake_uid and len(fake_uid) >= 14 else None
        self.ok = bool(fake_uid)
        self.name = "fake reader" if fake_uid else ""
        self.error = ""
        if fake_uid:
            return
        try:
            import ctypes
            from ctypes import wintypes

            self.ctypes = ctypes
            self.H = ctypes.c_size_t
            w = ctypes.WinDLL("winscard.dll")
            H, D = self.H, wintypes.DWORD
            self.D = D

            class IO(ctypes.Structure):
                _fields_ = [("dwProtocol", D), ("cbPciLength", D)]

            self.IO = IO
            w.SCardEstablishContext.argtypes = [D, ctypes.c_void_p, ctypes.c_void_p, ctypes.POINTER(H)]
            w.SCardListReadersW.argtypes = [H, wintypes.LPCWSTR, ctypes.c_void_p, ctypes.POINTER(D)]
            w.SCardConnectW.argtypes = [H, wintypes.LPCWSTR, D, D, ctypes.POINTER(H), ctypes.POINTER(D)]
            w.SCardTransmit.argtypes = [H, ctypes.POINTER(IO), ctypes.c_char_p, D,
                                        ctypes.c_void_p, ctypes.c_void_p, ctypes.POINTER(D)]
            w.SCardDisconnect.argtypes = [H, D]
            for fn in (w.SCardEstablishContext, w.SCardListReadersW, w.SCardConnectW,
                       w.SCardTransmit, w.SCardDisconnect):
                fn.restype = ctypes.c_long
            self.w = w
            self.ctx = H()
            if w.SCardEstablishContext(0, None, None, ctypes.byref(self.ctx)) != 0:
                raise RuntimeError("the Windows Smart Card service is not running")
            self._find_reader()
        except Exception as err:
            self.error = str(err)

    def _find_reader(self):
        ctypes, D = self.ctypes, self.D
        size = D(0)
        self.w.SCardListReadersW(self.ctx, None, None, ctypes.byref(size))
        buf = ctypes.create_unicode_buffer(size.value)
        self.w.SCardListReadersW(self.ctx, None, buf, ctypes.byref(size))
        names = [n for n in buf[:size.value].split("\0") if n]
        if not names:
            self.ok = False
            self.error = "no card reader found (is the ACR122U plugged in?)"
            return
        self.name = names[0]
        self.ok = True
        self.error = ""

    def read_once(self):
        if self.fake:
            return self.fake
        ctypes, D, H = self.ctypes, self.D, self.H
        card, proto = H(), D()
        if self.w.SCardConnectW(self.ctx, self.name, 2, 3, ctypes.byref(card), ctypes.byref(proto)) != 0:
            return None
        recv = ctypes.create_string_buffer(258)
        rlen = D(258)
        pci = self.IO(proto.value, 8)
        apdu = b"\xFF\xCA\x00\x00\x00"  # ask the reader for the tag UID
        rc = self.w.SCardTransmit(card, ctypes.byref(pci), apdu, len(apdu), None, recv, ctypes.byref(rlen))
        self.w.SCardDisconnect(card, 0)
        data = recv.raw[:rlen.value]
        if rc == 0 and data[-2:] == b"\x90\x00" and len(data) > 2:
            return data[:-2].hex().upper()
        return None

    # ---- securing (needs the card to stay connected for the whole procedure)
    def secure(self, uid_hex, pwd, pack, timeout):
        """Wait for the tag, make sure it is the expected one, then secure it. Returns the counter."""
        if self.fake:
            tag = self.fake_tag or FakeNtag215("04AABBCCDDEE80")
            tag.new_session()
            return secure_ntag215(tag, uid_hex if self.fake_tag else tag.uid(), pwd, pack)
        ctypes, D, H = self.ctypes, self.D, self.H
        end = time.time() + timeout
        while True:
            card, proto = H(), D()
            if self.w.SCardConnectW(self.ctx, self.name, 2, 3, ctypes.byref(card), ctypes.byref(proto)) == 0:
                break
            if time.time() >= end:
                raise SecureError("No tag was placed on the reader.")
            time.sleep(0.2)
        try:
            return secure_ntag215(PcscLink(self, card, proto), uid_hex, pwd, pack)
        finally:
            self.w.SCardDisconnect(card, 0)

    def transmit(self, card, proto, apdu):
        ctypes, D = self.ctypes, self.D
        recv = ctypes.create_string_buffer(300)
        rlen = D(300)
        pci = self.IO(proto.value, 8)
        rc = self.w.SCardTransmit(card, ctypes.byref(pci), bytes(apdu), len(apdu), None, recv, ctypes.byref(rlen))
        if rc != 0:
            raise IOError("reader error 0x%X" % (rc & 0xFFFFFFFF))
        return recv.raw[:rlen.value]

    def wait_for_tag(self, timeout):
        end = time.time() + timeout
        while time.time() < end:
            uid = self.read_once()
            if uid:
                return uid
            time.sleep(0.2)
        return None


class PcscLink:
    """The ACR122U talking to one tag, using its documented PC/SC commands."""

    def __init__(self, reader, card, proto):
        self.r, self.card, self.proto = reader, card, proto

    def _apdu(self, apdu):
        data = self.r.transmit(self.card, self.proto, apdu)
        if data[-2:] != b"\x90\x00":
            raise IOError("the reader or tag refused the command")
        return data[:-2]

    def uid(self):
        return self._apdu(b"\xFF\xCA\x00\x00\x00").hex().upper()

    def read16(self, page):
        data = self._apdu(bytes([0xFF, 0xB0, 0x00, page, 0x10]))
        if len(data) != 16:
            raise IOError("short read")
        return data

    def write(self, page, data):
        self._apdu(bytes([0xFF, 0xD6, 0x00, page, 0x04]) + bytes(data))

    def thru(self, cmd):
        """Send raw tag bytes through the reader's PN532 (InCommunicateThru)."""
        cmd = bytes(cmd)
        data = self._apdu(bytes([0xFF, 0x00, 0x00, 0x00, len(cmd) + 2, 0xD4, 0x42]) + cmd)
        if len(data) < 3 or data[0] != 0xD5 or data[1] != 0x43 or data[2] != 0x00:
            raise IOError("the tag did not accept the command")
        return data[3:]


READER = None


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def _send(self, code, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        origin = self.headers.get("Origin", "")
        if ORIGIN_OK.match(origin):
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Vary", "Origin")
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        origin = self.headers.get("Origin", "")
        self.send_response(204)
        if ORIGIN_OK.match(origin):
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
            self.send_header("Access-Control-Allow-Headers", "Content-Type")
            self.send_header("Vary", "Origin")
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_POST(self):
        origin = self.headers.get("Origin", "")
        if origin and not ORIGIN_OK.match(origin):
            return self._send(403, {"error": "origin not allowed"})
        url = urlparse(self.path)
        if url.path != "/secure":
            return self._send(404, {"error": "not found"})
        try:
            length = int(self.headers.get("Content-Length", "0"))
            body = json.loads(self.rfile.read(min(length, 4096)) or b"{}")
            uid = str(body.get("uid", "")).upper()
            pwd = bytes.fromhex(str(body.get("password", "")))
            pack = bytes.fromhex(str(body.get("pack", "")))
            timeout = min(max(int(body.get("timeout", 15)), 1), 60)
        except (ValueError, TypeError, json.JSONDecodeError):
            return self._send(400, {"error": "bad request"})
        if not re.fullmatch(r"[0-9A-F]{8,20}", uid) or len(pwd) != 4 or len(pack) != 2:
            return self._send(400, {"error": "bad request"})
        if not READER.ok and hasattr(READER, "w"):
            READER._find_reader()
        if not READER.ok:
            return self._send(503, {"error": READER.error or "no reader"})
        if not LOCK.acquire(blocking=False):
            return self._send(409, {"error": "the reader is busy"})
        try:
            counter = READER.secure(uid, pwd, pack, timeout)
        except SecureError as err:
            return self._send(422, {"error": str(err)})
        except Exception as err:
            return self._send(500, {"error": "Could not secure the tag (%s). Place it flat on the reader and try again." % err})
        finally:
            LOCK.release()
        return self._send(200, {"ok": True, "uid": uid, "counter": counter})

    def do_GET(self):
        # Refuse pages from other websites (a stranger's web page must not read your tags).
        origin = self.headers.get("Origin", "")
        if origin and not ORIGIN_OK.match(origin):
            return self._send(403, {"error": "origin not allowed"})
        url = urlparse(self.path)
        if url.path == "/status":
            return self._send(200, {"reader": READER.name if READER.ok else None, "error": READER.error})
        if url.path == "/uid":
            if not READER.ok and hasattr(READER, "w"):
                READER._find_reader()  # reader may have been plugged in after start
            if not READER.ok:
                return self._send(503, {"error": READER.error or "no reader"})
            try:
                timeout = min(max(int(parse_qs(url.query).get("timeout", ["15"])[0]), 1), 60)
            except ValueError:
                timeout = 15
            if not LOCK.acquire(blocking=False):
                return self._send(409, {"error": "another read is already waiting"})
            try:
                uid = READER.wait_for_tag(timeout)
            finally:
                LOCK.release()
            if not uid:
                return self._send(408, {"error": "no tag was placed on the reader"})
            return self._send(200, {"uid": uid})
        self._send(404, {"error": "not found"})


def main():
    global READER
    fake = None
    if "--fake" in sys.argv:
        i = sys.argv.index("--fake")
        fake = sys.argv[i + 1].upper() if i + 1 < len(sys.argv) else "04AABBCC410289"
    READER = Reader(fake)
    if READER.ok:
        print("Reader:", READER.name)
    else:
        print("Reader problem:", READER.error)
        print("The bridge will keep running; plug the reader in and try again from the dashboard.")
    print("Bridge ready on http://127.0.0.1:%d  (Ctrl+C to stop)" % PORT)
    try:
        ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
    except KeyboardInterrupt:
        print("\nBye.")


if __name__ == "__main__":
    main()
