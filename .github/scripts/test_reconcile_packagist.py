import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('publisher', Path(__file__).with_name('reconcile-packagist.py'))
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class ReleaseVersionTest(unittest.TestCase):
    def test_zero_major_packages_keep_the_minor_compatibility_line(self):
        self.assertEqual('0.1.3', publisher.release_version(['v0.1.2', 'v0.2.9', 'v1.0.0'], '0.1.0'))

    def test_existing_versions_are_never_reused_for_changed_source(self):
        self.assertEqual('1.2.4', publisher.release_version(['v1.2.1', 'v1.2.3', 'feature'], '1.0.0'))

    def test_new_declared_compatibility_line_starts_at_declared_version(self):
        self.assertEqual('2.0.0', publisher.release_version(['v1.9.4'], '2.0.0'))

    def test_explicit_patch_bump_is_preserved(self):
        self.assertEqual('0.1.4', publisher.release_version(['v0.1.2'], '0.1.4'))


if __name__ == '__main__':
    unittest.main()
