"""
Weaver-ID tag signature check: is this NTAG215 a genuine NXP chip?

Every genuine NXP NTAG21x carries a 32-byte signature NXP made over the chip's
UID at the factory (ECDSA, curve secp128r1). Anyone holding NXP's public key
can check it. A cheap clone card with a copied UID cannot make a valid one.

This is NOT the signed read counter. It proves the chip and its UID are
genuine; it does not prove the counter number is real.

Usage (Windows, no packages needed, ACR122U plugged in):
    python check_tag_signature.py                  read one tag on the reader and check it
    python check_tag_signature.py --loop           keep checking tags, one after another
    python check_tag_signature.py --uid 04EE45DAA34084 --sig EBB6...D73B
                                                   check a UID and signature read some other way

The check uses only public information. It never writes to a tag and never
changes its counter.
"""
import os
import sys
import time

# ---------------------------------------------------------------- curve secp128r1
P = 0xFFFFFFFDFFFFFFFFFFFFFFFFFFFFFFFF
A = 0xFFFFFFFDFFFFFFFFFFFFFFFFFFFFFFFC
B = 0xE87579C11079F43DD824993C2CEE5ED3
GX = 0x161FF7528B899B2D0C28607CA52C5B86
GY = 0xCF5AC8395BAFEB13C02DA292DDED7A83
N = 0xFFFFFFFE0000000075A30D1B9038A115
G = (GX, GY)

# NXP public keys (uncompressed points, 04 || X || Y). These come from a
# published open-source verifier, not from NXP's own (NDA-only) application
# note. The Ultralight EV1 key is confirmed by a real chip's signature (see
# the test file). The NTAG/Ultralight key is confirmed only when a real tag of
# yours verifies with it.
NXP_KEYS = {
    "NXP NTAG / Ultralight": "04494e1a386d3d3cfe3dc10e5de68a499b1c202db5b132393e89ed19fe5be8bc61",
    "NXP Ultralight EV1": "0490933bdcd6e99b4e255e3da55389a827564e11718e017292faf23226a96614b8",
}

READ_SIG = b"\x3c\x00"  # NTAG21x READ_SIG command: code 3Ch, address 00h


def _inv(x, m):
    return pow(x, -1, m)


def _add(p, q):
    if p is None:
        return q
    if q is None:
        return p
    if p[0] == q[0] and (p[1] + q[1]) % P == 0:
        return None
    if p == q:
        lam = (3 * p[0] * p[0] + A) * _inv(2 * p[1], P) % P
    else:
        lam = (q[1] - p[1]) * _inv(q[0] - p[0], P) % P
    x = (lam * lam - p[0] - q[0]) % P
    return (x, (lam * (p[0] - x) - p[1]) % P)


def _mul(k, point):
    result = None
    while k:
        if k & 1:
            result = _add(result, point)
        point = _add(point, point)
        k >>= 1
    return result


def on_curve(point):
    x, y = point
    return (y * y - (x * x * x + A * x + B)) % P == 0


def decode_key(hex_key):
    raw = bytes.fromhex(hex_key)
    if len(raw) != 33 or raw[0] != 0x04:
        raise ValueError("public key must be an uncompressed secp128r1 point")
    point = (int.from_bytes(raw[1:17], "big"), int.from_bytes(raw[17:], "big"))
    if not on_curve(point):
        raise ValueError("public key is not on the curve")
    return point


def verify(uid, signature, public_key):
    """ECDSA check. The signed message is the UID bytes as one big number (no hash)."""
    if len(signature) != 32:
        return False
    r = int.from_bytes(signature[:16], "big")
    s = int.from_bytes(signature[16:], "big")
    if not (0 < r < N and 0 < s < N):
        return False
    z = int.from_bytes(uid, "big")
    w = _inv(s, N)
    point = _add(_mul(z * w % N, G), _mul(r * w % N, public_key))
    return point is not None and point[0] % N == r


def check(uid_hex, sig_hex):
    """Returns (status, key_name). status: valid, invalid, no_signature or bad_input."""
    try:
        uid = bytes.fromhex(uid_hex.replace(":", "").replace(" ", ""))
        sig = bytes.fromhex(sig_hex.replace(":", "").replace(" ", ""))
    except ValueError:
        return "bad_input", None
    if len(uid) != 7 or len(sig) != 32:
        return "bad_input", None
    if not any(sig):
        return "no_signature", None
    for name, hex_key in NXP_KEYS.items():
        if verify(uid, sig, decode_key(hex_key)):
            return "valid", name
    return "invalid", None


MESSAGES = {
    "valid": "VALID: the signature checks out with the %s key. This is a genuine NXP chip with this UID.",
    "invalid": ("NOT VALID: the signature does not verify with any known NXP key. Either this chip is not made by "
                "NXP (a clone or a cheap compatible chip), or the key list is missing the right key."),
    "no_signature": "NO SIGNATURE: the chip returned all zeros, so it carries no NXP signature.",
    "bad_input": "Could not check: need a 7-byte UID and a 32-byte signature in hex.",
}


def describe(status, key_name):
    text = MESSAGES[status]
    return text % key_name if "%s" in text else text


def read_signature(link):
    """Read UID and signature through the reader. `link` offers uid() and thru() like the bridge's PcscLink."""
    uid = link.uid()
    reply = bytes(link.thru(READ_SIG))
    if len(reply) < 32:
        raise IOError("The tag did not return a signature. Hold it flat on the reader and try again.")
    return uid, reply[:32].hex().upper()


# ---------------------------------------------------------------- reader (Windows)
def _connect(reader, timeout):
    ctypes, D, H = reader.ctypes, reader.D, reader.H
    end = time.time() + timeout
    while True:
        card, proto = H(), D()
        if reader.w.SCardConnectW(reader.ctx, reader.name, 2, 3, ctypes.byref(card), ctypes.byref(proto)) == 0:
            return card, proto
        if time.time() >= end:
            return None, None
        time.sleep(0.2)


def check_tag_on_reader(reader, timeout=20):
    import reader_bridge as rb

    card, proto = _connect(reader, timeout)
    if card is None:
        return None
    try:
        uid, sig = read_signature(rb.PcscLink(reader, card, proto))
    finally:
        reader.w.SCardDisconnect(card, 0)
    status, key = check(uid, sig)
    return uid, sig, status, key


def main(argv):
    if "--uid" in argv and "--sig" in argv:
        uid = argv[argv.index("--uid") + 1]
        sig = argv[argv.index("--sig") + 1]
        status, key = check(uid, sig)
        print(describe(status, key))
        return 0 if status == "valid" else 1

    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import reader_bridge as rb

    reader = rb.Reader()
    if not reader.ok:
        print("Reader problem:", reader.error)
        return 2
    print("Reader:", reader.name)
    loop = "--loop" in argv
    exit_code = 1
    while True:
        print("\nPlace a tag on the reader ...")
        try:
            outcome = check_tag_on_reader(reader)
        except IOError as err:
            print("Error:", err)
            outcome = None
            if not loop:
                return 2
        if outcome is None:
            if not loop:
                print("No tag was placed on the reader.")
                return 2
        else:
            uid, sig, status, key = outcome
            print("UID:       ", uid)
            print("Signature: ", sig)
            print(describe(status, key))
            exit_code = 0 if status == "valid" else 1
        if not loop:
            return exit_code
        print("Remove the tag, then place the next one. Ctrl+C to stop.")
        while reader.read_once():
            time.sleep(0.3)


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print("\nBye.")
