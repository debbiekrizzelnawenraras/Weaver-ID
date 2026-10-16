"""
Tests for check_tag_signature.py. Run from the tools folder:
    python -m unittest test_check_tag_signature
No reader needed.
"""
import unittest

import check_tag_signature as cts

# A real chip's UID and signature, taken from the README of an open-source
# verifier (node-nxp-originality-verifier). It verifies with the Ultralight EV1
# key, which confirms the curve, the key, the signature layout and the message
# encoding used here against something NXP actually signed.
REAL_UID = "04EE45DAA34084"
REAL_SIG = "EBB6102BFF74B087D18A57A54BC375159A04EA9BC61080B7F4A85AFE1587D73B"


def sign(uid, private_key, k):
    """Test-only signer (never used by the tool itself)."""
    z = int.from_bytes(uid, "big")
    r = cts._mul(k, cts.G)[0] % cts.N
    s = pow(k, -1, cts.N) * (z + r * private_key) % cts.N
    return r.to_bytes(16, "big") + s.to_bytes(16, "big")


class FakeLink:
    def __init__(self, uid="04AABBCCDDEE80", sig=bytes(32)):
        self._uid, self._sig = uid, sig
        self.sent = []

    def uid(self):
        return self._uid

    def thru(self, cmd):
        self.sent.append(bytes(cmd))
        return self._sig


class CurveTests(unittest.TestCase):
    def test_generator_is_on_the_curve_and_has_the_right_order(self):
        self.assertTrue(cts.on_curve(cts.G))
        self.assertIsNone(cts._mul(cts.N, cts.G))

    def test_all_listed_keys_are_valid_points(self):
        for name, hex_key in cts.NXP_KEYS.items():
            self.assertTrue(cts.on_curve(cts.decode_key(hex_key)), name)

    def test_bad_keys_are_refused(self):
        with self.assertRaises(ValueError):
            cts.decode_key("04" + "00" * 32)
        with self.assertRaises(ValueError):
            cts.decode_key("02" + "11" * 16)


class VerifyTests(unittest.TestCase):
    def test_a_real_nxp_signature_verifies(self):
        status, key = cts.check(REAL_UID, REAL_SIG)
        self.assertEqual(status, "valid")
        self.assertEqual(key, "NXP Ultralight EV1")

    def test_accepts_colons_spaces_and_lowercase(self):
        uid = ":".join(REAL_UID[i:i + 2] for i in range(0, 14, 2)).lower()
        self.assertEqual(cts.check(uid, REAL_SIG.lower())[0], "valid")

    def test_another_uid_with_the_same_signature_fails(self):
        self.assertEqual(cts.check("04EE45DAA34085", REAL_SIG)[0], "invalid")

    def test_a_changed_signature_fails(self):
        flipped = REAL_SIG[:-1] + ("0" if REAL_SIG[-1] != "0" else "1")
        self.assertEqual(cts.check(REAL_UID, flipped)[0], "invalid")

    def test_swapped_halves_fail(self):
        swapped = REAL_SIG[32:] + REAL_SIG[:32]
        self.assertEqual(cts.check(REAL_UID, swapped)[0], "invalid")

    def test_all_zero_signature_means_no_signature(self):
        self.assertEqual(cts.check(REAL_UID, "00" * 32)[0], "no_signature")

    def test_bad_input_is_reported_not_raised(self):
        self.assertEqual(cts.check("xyz", REAL_SIG)[0], "bad_input")
        self.assertEqual(cts.check("04AABB", REAL_SIG)[0], "bad_input")
        self.assertEqual(cts.check(REAL_UID, "AB" * 31)[0], "bad_input")

    def test_signing_and_verifying_with_our_own_key_round_trips(self):
        private_key = 0x1234567890ABCDEF1234567890ABCD
        public_key = cts._mul(private_key, cts.G)
        uid = bytes.fromhex("04112233445566")
        signature = sign(uid, private_key, k=0x0F0E0D0C0B0A09080706050403020100)
        self.assertTrue(cts.verify(uid, signature, public_key))
        self.assertFalse(cts.verify(bytes.fromhex("04112233445567"), signature, public_key))
        other_key = cts._mul(private_key + 1, cts.G)
        self.assertFalse(cts.verify(uid, signature, other_key))

    def test_out_of_range_values_are_refused(self):
        public_key = cts.decode_key(cts.NXP_KEYS["NXP Ultralight EV1"])
        uid = bytes.fromhex(REAL_UID)
        self.assertFalse(cts.verify(uid, bytes(32), public_key))
        self.assertFalse(cts.verify(uid, b"\xff" * 32, public_key))
        self.assertFalse(cts.verify(uid, bytes(31), public_key))


class ReadTests(unittest.TestCase):
    def test_reads_uid_and_signature_with_the_read_sig_command(self):
        link = FakeLink(uid=REAL_UID, sig=bytes.fromhex(REAL_SIG))
        uid, sig = cts.read_signature(link)
        self.assertEqual((uid, sig), (REAL_UID, REAL_SIG))
        self.assertEqual(link.sent, [b"\x3c\x00"])

    def test_a_short_reply_is_an_error(self):
        with self.assertRaises(IOError):
            cts.read_signature(FakeLink(sig=bytes(5)))

    def test_describe_gives_plain_messages(self):
        self.assertIn("genuine NXP chip", cts.describe("valid", "NXP NTAG / Ultralight"))
        self.assertIn("NOT VALID", cts.describe("invalid", None))
        self.assertIn("all zeros", cts.describe("no_signature", None))


if __name__ == "__main__":
    unittest.main()
