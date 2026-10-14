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


class Reader:
    """Windows smart card service (winscard.dll) through ctypes."""

    def __init__(self, fake_uid=None):
        self.fake = fake_uid
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

    def wait_for_tag(self, timeout):
        end = time.time() + timeout
        while time.time() < end:
            uid = self.read_once()
            if uid:
                return uid
            time.sleep(0.2)
        return None


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
        self._send(204, {})

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
